<?php

namespace App\EventListener;

use App\Repository\BlogRepository;
use Presta\SitemapBundle\Event\SitemapPopulateEvent;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Event listener of the Sitemap bundle.
 * This class is used to populate the sitemap with the blog posts.
 */
#[AsEventListener(event: SitemapPopulateEvent::class, method: 'onSitemapPopulate')]
readonly class SiteMapEventListener
{
    public function __construct(
        public BlogRepository $blogPostRepository
    )
    {
    }

    public function onSitemapPopulate(SitemapPopulateEvent $event): void
    {
        $posts = $this->blogPostRepository->findAllVisible();

        $urlContainer = $event->getUrlContainer();
        $urlGenerator = $event->getUrlGenerator();

        foreach ($posts as $post) {
            $url = new UrlConcrete(
                $urlGenerator->generate(
                    'app_blog_show',
                    ['slugFr' => $post->getSlugFr()],
                    UrlGeneratorInterface::ABSOLUTE_URL)
            );
            $url->setChangefreq(UrlConcrete::CHANGEFREQ_MONTHLY);
            $url->setLastmod($post->getCreatedAt());
            $urlContainer->addUrl(
                $url,
                'blog',
            );
        }

        foreach ($posts as $post) {
            $url = new UrlConcrete(
                $urlGenerator->generate(
                    'app_blog_show',
                    ['slugEn' => $post->getSlugEn()],
                    UrlGeneratorInterface::ABSOLUTE_URL)
            );
            $url->setChangefreq(UrlConcrete::CHANGEFREQ_MONTHLY);
            $url->setLastmod($post->getCreatedAt());
            $urlContainer->addUrl(
                $url,
                'blog',
            );
        }
    }
}
