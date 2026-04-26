<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(KernelEvents::RESPONSE)]
final readonly class AdminNoIndexListener
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        // URL pattern: /{_locale}/{adminPrefix}/admin... — anchored to the
        // exact secret prefix so unrelated routes can never trigger noindex.
        if (!preg_match('#^/(fr|en)/'.preg_quote($this->adminPathPrefix, '#').'/admin(?:$|/)#', $event->getRequest()->getPathInfo())) {
            return;
        }
        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}
