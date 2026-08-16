<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Replays the REAL live-component HTTP flow of the create-dossier modal:
 * mount on the list page, openModal, then an offer chip click with the
 * props the browser would resend (only way to see hydration/morph bugs).
 */
final class DossierCreateLiveFlowTest extends WebTestCase
{
    public function testOfferClickKeepsTheModalStable(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')->setParameter('e', 'repro-dossier-live@example.com')->execute();

        $admin = (new User())
            ->setEmail('repro-dossier-live@example.com')
            ->setFirstName('Repro')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);

        $adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $crawler = $client->request('GET', sprintf('/fr/%s/admin/dossiers', $adminPrefix));
        self::assertResponseIsSuccessful();

        $node = $crawler->filter('[data-testid="dossier-create"]')->first();
        $props = json_decode((string) $node->attr('data-live-props-value'), true);
        $csrf = $node->attr('data-live-csrf-value');
        $url = (string) $node->attr('data-live-url-value');

        [$props, $openHtml] = $this->action($client, $url, $csrf, $props, 'openModal', []);
        self::assertStringContainsString('data-testid="dossier-create-modal"', $openHtml);

        [$props, $afterHtml] = $this->action($client, $url, $csrf, $props, 'chooseOffer', ['offer' => 'accompagne']);

        // Modal still open, offer picked.
        self::assertStringContainsString('data-testid="dossier-create-modal"', $afterHtml);
        self::assertStringContainsString('aria-pressed="true"', $afterHtml);

        // Nothing must "appear": no validation errors while nothing was
        // submitted, and no duplicated modal/person markup.
        $after = new Crawler($afterHtml);
        $open = new Crawler($openHtml);
        self::assertSame(
            $open->filter('[role="alert"]')->count(),
            $after->filter('[role="alert"]')->count(),
            'Validation errors appeared after a simple offer click.',
        );
        self::assertSame(1, $after->filter('[data-testid="dossier-create-modal"]')->count());
        self::assertSame(
            $open->filter('[data-testid="dossier-create-person"]')->count(),
            $after->filter('[data-testid="dossier-create-person"]')->count(),
        );
    }

    /** @return array{0: array, 1: string} live props + full HTML after re-render */
    private function action(KernelBrowser $client, string $url, ?string $csrf, array $props, string $action, array $args): array
    {
        $client->request(
            'POST',
            $url.'/'.$action,
            [
                'data' => json_encode([
                    'props' => $props,
                    'args' => $args,
                ]),
            ],
            [],
            array_filter([
                'HTTP_ACCEPT' => 'application/vnd.live-component+html',
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ]),
        );
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));

        $html = (string) $response->getContent();
        $crawler = new Crawler($html);
        $root = $crawler->filter('[data-testid="dossier-create"]')->first();

        return [json_decode((string) $root->attr('data-live-props-value'), true), $html];
    }
}
