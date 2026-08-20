<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every back-office response is uncacheable, at every layer. Symfony's
 * default for session responses is "private", which o2switch's LiteSpeed
 * cache ignores: an admin page could be pinned and served stale (a visit
 * created by a colleague only showing up after re-login). Public pages are
 * untouched, so the perf story stays intact; LiveComponent endpoints are
 * stateful renders and must never be cached either.
 */
#[AsEventListener(KernelEvents::RESPONSE)]
final readonly class AdminNoCacheListener
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        $isAdmin = 1 === preg_match('#^/(fr|en)/'.preg_quote($this->adminPathPrefix, '#').'/admin(?:$|/)#', $path);
        if (!$isAdmin && !str_starts_with($path, '/_components/')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
    }
}
