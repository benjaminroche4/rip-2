<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\EventListener\AdminMutationRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Defense-in-depth limiter on Live admin mutations: only POST/PATCH/DELETE
 * calls to ux_live_component targeting a back-office component (Admin:,
 * Dossier:, Visit:, RealEstateAgent:) consume the per-IP budget; everything
 * else (reads, public components, other routes) must stay unmetered. Uses a real RateLimiterFactory over an in-memory
 * storage with a tiny limit so the cap is observable deterministically.
 */
final class AdminMutationRateLimitListenerTest extends TestCase
{
    public function testItBlocksAdminMutationsBeyondThePerIpLimit(): void
    {
        $listener = $this->listener(limit: 2);

        $listener($this->adminMutation('10.0.0.1'));
        $listener($this->adminMutation('10.0.0.1'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->adminMutation('10.0.0.1'));
    }

    public function testAnotherIpKeepsItsOwnBudget(): void
    {
        $listener = $this->listener(limit: 1);

        $listener($this->adminMutation('10.0.0.1'));
        $listener($this->adminMutation('10.0.0.2'));
        $this->addToAssertionCount(1);
    }

    public function testReadsAndNonAdminRequestsNeverConsumeTheBudget(): void
    {
        $listener = $this->listener(limit: 1);

        // None of these may consume: GET re-renders, public Live
        // components, and ordinary POST routes.
        $listener($this->event('GET', 'ux_live_component', 'Admin:ContactList', '10.0.0.1'));
        $listener($this->event('POST', 'ux_live_component', 'Marketplace:Search', '10.0.0.1'));
        $listener($this->event('POST', 'app_contact', 'Admin:ContactList', '10.0.0.1'));

        // The full budget is still available for a real admin mutation.
        $listener($this->adminMutation('10.0.0.1'));
        $this->addToAssertionCount(1);
    }

    public function testEveryBackOfficeNamespaceConsumesTheBudget(): void
    {
        // Le cap "session détournée" doit couvrir tout le BO, pas
        // seulement les composants Admin: (régression corrigée août 2026).
        foreach (['Dossier:Notes', 'Visit:VisitForm', 'RealEstateAgent:AgencyDetails'] as $component) {
            $listener = $this->listener(limit: 1);
            $listener($this->event('POST', 'ux_live_component', $component, '10.0.0.1'));

            try {
                $listener($this->event('POST', 'ux_live_component', $component, '10.0.0.1'));
                self::fail($component.' must consume the admin mutation budget.');
            } catch (TooManyRequestsHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSubRequestsAreIgnored(): void
    {
        $listener = $this->listener(limit: 1);
        $request = $this->request('POST', 'ux_live_component', 'Admin:ContactList', '10.0.0.1');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $listener(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));

        // Budget untouched: the main-request mutation still passes.
        $listener($this->adminMutation('10.0.0.1'));
        $this->addToAssertionCount(1);
    }

    private function listener(int $limit): AdminMutationRateLimitListener
    {
        return new AdminMutationRateLimitListener(new RateLimiterFactory([
            'id' => 'admin_mutation',
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => '1 minute',
        ], new InMemoryStorage()));
    }

    private function adminMutation(string $ip): RequestEvent
    {
        return $this->event('POST', 'ux_live_component', 'Admin:ContactList', $ip);
    }

    private function event(string $method, string $route, string $component, string $ip): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request($method, $route, $component, $ip),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function request(string $method, string $route, string $component, string $ip): Request
    {
        $request = Request::create('/_components/x', $method, server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_live_component', $component);

        return $request;
    }
}
