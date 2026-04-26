<?php

namespace App\EventListener;

use App\Marketplace\Repository\PropertyRepository;
use App\Service\SanityService;
use App\Marketplace\Twig\Extension\PropertyUrlExtension;
use Presta\SitemapBundle\Event\SitemapPopulateEvent;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: SitemapPopulateEvent::class, method: 'onSitemapPopulate')]
readonly class SiteMapEventListener
{
    public function __construct(
        private SanityService $sanityService,
        private PropertyRepository $propertyRepository,
        private PropertyUrlExtension $propertyUrlExtension,
    )
    {
    }

    public function onSitemapPopulate(SitemapPopulateEvent $event): void
    {
        $urlContainer = $event->getUrlContainer();
        $urlGenerator = $event->getUrlGenerator();

        foreach (['fr', 'en'] as $locale) {
            // Blog list page
            $listUrl = new UrlConcrete(
                $urlGenerator->generate(
                    'app_blog',
                    ['_locale' => $locale],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            );
            $listUrl->setChangefreq(UrlConcrete::CHANGEFREQ_DAILY);
            $listUrl->setPriority(0.8);
            $urlContainer->addUrl($listUrl, 'blog');

            // Blog posts
            $posts = $this->sanityService->query(
                '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] {
                    "slug": slug.current,
                    _createdAt
                }',
                ['locale' => $locale]
            );

            foreach ($posts as $post) {
                $url = new UrlConcrete(
                    $urlGenerator->generate(
                        'app_blog_show',
                        [
                            '_locale' => $locale,
                            'slug' => $post['slug'],
                        ],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    )
                );
                $url->setChangefreq(UrlConcrete::CHANGEFREQ_MONTHLY);
                if (!empty($post['_createdAt'])) {
                    $url->setLastmod(new \DateTime($post['_createdAt']));
                }
                $urlContainer->addUrl($url, 'blog');
            }

            // Property list page
            $propertyListUrl = new UrlConcrete(
                $urlGenerator->generate(
                    'app_property',
                    ['_locale' => $locale],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            );
            $propertyListUrl->setChangefreq(UrlConcrete::CHANGEFREQ_DAILY);
            $propertyListUrl->setPriority(0.9);
            $urlContainer->addUrl($propertyListUrl, 'properties');

            // Property detail pages
            foreach ($this->propertyRepository->findAll($locale) as $property) {
                $url = new UrlConcrete(
                    $this->propertyUrlExtension->propertyShowPath($property, $locale, true)
                );
                $url->setChangefreq(UrlConcrete::CHANGEFREQ_DAILY);
                $url->setPriority(0.8);
                if (!empty($property['updatedAt'])) {
                    $url->setLastmod(new \DateTime($property['updatedAt']));
                } elseif (!empty($property['createdAt'])) {
                    $url->setLastmod(new \DateTime($property['createdAt']));
                }
                $urlContainer->addUrl($url, 'properties');
            }
        }
    }
}
