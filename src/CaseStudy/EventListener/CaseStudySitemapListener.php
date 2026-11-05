<?php

namespace App\CaseStudy\EventListener;

use App\CaseStudy\Repository\CaseStudyRepositoryInterface;
use Presta\SitemapBundle\Event\SitemapPopulateEvent;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Adds every case study page, in both locales, to the "case_studies" sitemap section.
 * The listing itself is declared on its route (options.sitemap).
 */
#[AsEventListener(event: SitemapPopulateEvent::class)]
final readonly class CaseStudySitemapListener
{
    public function __construct(
        private CaseStudyRepositoryInterface $caseStudyRepository,
    ) {
    }

    public function __invoke(SitemapPopulateEvent $event): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach ($this->caseStudyRepository->findAll($locale) as $caseStudy) {
                $url = new UrlConcrete(
                    $event->getUrlGenerator()->generate(
                        'app_case_study_show',
                        ['_locale' => $locale, 'slug' => $caseStudy->slug],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    \DateTime::createFromImmutable($caseStudy->publishedAt),
                    UrlConcrete::CHANGEFREQ_MONTHLY,
                    0.6,
                );
                $event->getUrlContainer()->addUrl($url, 'case_studies');
            }
        }
    }
}
