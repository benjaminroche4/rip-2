<?php

namespace App\CaseStudy\Controller;

use App\CaseStudy\Domain\CaseStudy;
use App\CaseStudy\Domain\CaseStudySegment;
use App\CaseStudy\Map\AreaMapFactory;
use App\CaseStudy\Repository\CaseStudyRepositoryInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaseStudyController extends AbstractController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly CaseStudyRepositoryInterface $caseStudyRepository,
        private readonly AreaMapFactory $areaMapFactory,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/etudes-de-cas',
            'en' => '/{_locale}/case-studies',
        ],
        name: 'app_case_studies',
        options: [
            'sitemap' => [
                'priority' => 0.7,
                'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
            ],
        ],
    )]
    public function list(Request $request, string $_locale): Response
    {
        // Unknown values (old links, typos) fall back to the unfiltered list instead of an empty page
        $activeSegment = CaseStudySegment::tryFrom((string) $request->query->get('category'));

        $all = $this->caseStudyRepository->findAll($_locale);
        // The featured one gets the big card on top (like the featured blog article), not a grid card
        $featured = array_values(array_filter($all, static fn (CaseStudy $caseStudy): bool => $caseStudy->featured))[0] ?? null;

        $listed = array_values(array_filter(
            null === $featured ? $all : array_filter($all, static fn (CaseStudy $caseStudy): bool => $caseStudy !== $featured),
            static fn (CaseStudy $caseStudy): bool => null === $activeSegment || $caseStudy->segment === $activeSegment,
        ));

        $totalPages = max(1, (int) ceil(\count($listed) / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        return $this->render('public/case_study/list.html.twig', [
            'featured' => $featured,
            'stats' => $this->stats($all),
            'caseStudies' => \array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => \count($all),
            'categories' => $this->segmentTabs($all),
            'activeCategory' => $activeSegment?->value,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/etudes-de-cas/{slug}',
            'en' => '/{_locale}/case-studies/{slug}',
        ],
        name: 'app_case_study_show',
    )]
    public function show(string $slug, string $_locale): Response
    {
        $caseStudy = $this->caseStudyRepository->findOneBySlug($slug, $_locale);
        if (null === $caseStudy) {
            // A slug of the other language (language switch on a case study not translated yet):
            // its translation if it exists, the listing otherwise. Unknown everywhere: 404.
            $other = $this->caseStudyRepository->findOneBySlug($slug, 'fr' === $_locale ? 'en' : 'fr');
            if (null === $other) {
                throw $this->createNotFoundException('The case study does not exist');
            }

            return null !== $other->alternateSlug
                ? $this->redirectToRoute('app_case_study_show', ['slug' => $other->alternateSlug], Response::HTTP_MOVED_PERMANENTLY)
                : $this->redirectToRoute('app_case_studies');
        }

        $others = array_filter(
            $this->caseStudyRepository->findAll($_locale),
            static fn (CaseStudy $other): bool => $other->slug !== $caseStudy->slug,
        );
        // Same audience first (a company reads company cases), then the most recent
        usort($others, static fn (CaseStudy $a, CaseStudy $b): int => [$b->segment === $caseStudy->segment, $b->publishedAt] <=> [$a->segment === $caseStudy->segment, $a->publishedAt]);

        return $this->render('public/case_study/show.html.twig', [
            'caseStudy' => $caseStudy,
            'map' => $this->areaMapFactory->create($caseStudy),
            'otherCaseStudies' => \array_slice($others, 0, 3),
        ]);
    }

    /**
     * Figures of the header line, computed from the published case studies (null below 2: not meaningful).
     *
     * @param list<CaseStudy> $caseStudies
     *
     * @return array{averageDays: int, origins: int}|null
     */
    private function stats(array $caseStudies): ?array
    {
        if (\count($caseStudies) < 2) {
            return null;
        }

        return [
            'averageDays' => (int) round(array_sum(array_map(static fn (CaseStudy $c): int => $c->daysToSettle, $caseStudies)) / \count($caseStudies)),
            'origins' => \count(array_unique(array_map(static fn (CaseStudy $c): string => mb_strtolower($c->origin), $caseStudies))),
        ];
    }

    /**
     * Tabs in the shape Blog:CategoryTabs expects, only for segments that have case studies.
     *
     * @param list<CaseStudy> $caseStudies
     *
     * @return list<array{name: string, slug: string, color: string, count: int}>
     */
    private function segmentTabs(array $caseStudies): array
    {
        $tabs = [];
        foreach (CaseStudySegment::cases() as $segment) {
            $count = \count(array_filter($caseStudies, static fn (CaseStudy $c): bool => $c->segment === $segment));
            if ($count > 0) {
                $tabs[] = ['name' => $segment->labelKey(), 'slug' => $segment->value, 'color' => $segment->color(), 'count' => $count];
            }
        }

        return $tabs;
    }
}
