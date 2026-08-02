<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Full-page caching on o2switch's LiteSpeed (LSCache) for the anonymous
 * public pages. A whitelisted GET with no user and no session gets
 * "X-LiteSpeed-Cache-Control: public" and is then served without ever
 * booting PHP; everything else is explicitly marked no-cache, because
 * LiteSpeed ignores the standard "Cache-Control: private".
 *
 * Personalisation stays alive through ESI (footer user chip): LiteSpeed
 * caches the anonymous shell and executes the fragment on every request.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
final readonly class LiteSpeedCacheListener
{
    private const TTL = 3600;

    /** Route base names (locale suffix stripped) → cache tag. */
    private const CACHEABLE_ROUTES = [
        'app_home' => 'static',
        'app_about_us' => 'static',
        'app_faq' => 'static',
        'app_pricing' => 'static',
        'app_legal_notice' => 'static',
        'app_privacy_policy' => 'static',
        'app_terms_and_conditions' => 'static',
        'app_sitemap' => 'static',
        'app_service_find_accommodation' => 'static',
        'app_service_companies' => 'static',
        'app_service_find_tenant' => 'static',
        'app_service_landlords' => 'static',
        'app_blog' => 'blog',
        'app_blog_show' => 'blog',
    ];

    public function __construct(
        private Security $security,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Never override an explicit decision (e.g. no-store confirmations).
        if ($response->headers->has('X-LiteSpeed-Cache-Control')) {
            return;
        }

        $route = (string) $request->attributes->get('_route');
        $baseRoute = preg_replace('/\.(fr|en)$/', '', $route);
        $tag = self::CACHEABLE_ROUTES[$baseRoute] ?? null;

        $cacheable = null !== $tag
            && $request->isMethodCacheable()
            && $response->isSuccessful()
            && null === $this->security->getUser()
            // A visitor with a session (form CSRF, flashes, marketplace...)
            // must never feed the shared cache.
            && !$request->hasPreviousSession();

        if ($cacheable) {
            $response->headers->set('X-LiteSpeed-Cache-Control', \sprintf('public, max-age=%d', self::TTL));
            $response->headers->set('X-LiteSpeed-Tag', $tag);

            return;
        }

        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
    }
}
