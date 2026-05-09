<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\Repository\CallRepository;
use App\Admin\Service\AdminKpiBuilder;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// NOTE: pas de #[IsGranted('ROLE_ADMIN')] ici. La sécurité passe par
// access_control dans security.yaml, ancré sur la vraie valeur de
// %admin_path_prefix%. Avec IsGranted au niveau controller, un mauvais
// prefix de format valide déclencherait un login redirect anonyme — ce qui
// révélerait le pattern de l'admin. Avec access_control + hash_equals dans
// le controller, un mauvais prefix tombe en 404 avant tout challenge auth.
#[Route(
    path: [
        'fr' => '/{_locale}/{adminPrefix}/admin',
        'en' => '/{_locale}/{adminPrefix}/admin',
    ],
    name: 'admin_',
    requirements: [
        '_locale' => 'fr|en',
        'adminPrefix' => '[a-zA-Z0-9_-]{16,64}',
    ],
)]
final class DashboardController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
        private readonly ContactRepository $contactRepository,
        private readonly CallRepository $callRepository,
        private readonly AdminUserRepository $adminUserRepository,
        private readonly AdminKpiBuilder $kpiBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(string $adminPrefix, Request $request): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $locale = $request->getLocale();
        $today = new \DateTimeImmutable('today');

        $contactsByMonth = $this->contactRepository->countByMonth(12);
        $callsByMonth = $this->callRepository->countByMonth(12);

        $contactsCounts = array_map(static fn (array $row): int => $row['count'], $contactsByMonth);
        $callsCounts = array_map(static fn (array $row): int => $row['count'], $callsByMonth);

        $chartLabels = array_map(
            fn (array $row): string => $this->formatYmLabel($row['ym'], $locale),
            $contactsByMonth,
        );

        // Rolling 7d vs the prior 7d. Calls API is capped at 12 months,
        // so a 14-day window is always inside the cached fetch.
        $windowStart = $today->modify('-13 days');
        $last7Start = $today->modify('-6 days');
        $next = $today->modify('+1 day');

        $contactsByDay = $this->contactRepository->countByDay($windowStart, $next);
        $callsByDay = $this->callRepository->countByDay($windowStart, $next);

        $contactsLast7 = $this->sumDays($contactsByDay, $last7Start, $next);
        $contactsPrev7 = $this->sumDays($contactsByDay, $windowStart, $last7Start);
        $callsLast7 = $this->sumDays($callsByDay, $last7Start, $next);
        $callsPrev7 = $this->sumDays($callsByDay, $windowStart, $last7Start);

        // Week-over-week contact requests (Mon→Sun, ISO week). Future days
        // inside the current week stay at 0 so the bars collapse to nothing.
        $currentWeekStart = $today->modify('monday this week');
        $previousWeekStart = $currentWeekStart->modify('-7 days');
        $nextWeekStart = $currentWeekStart->modify('+7 days');

        $contactsByDayWeeks = $this->contactRepository->countByDay($previousWeekStart, $nextWeekStart);

        $weekDayLabels = [];
        $currentWeekData = [];
        $previousWeekData = [];
        for ($i = 0; $i < 7; ++$i) {
            $currentDay = $currentWeekStart->modify('+'.$i.' days');
            $previousDay = $previousWeekStart->modify('+'.$i.' days');

            $weekDayLabels[] = $this->formatWeekdayLabel($currentDay, $locale);
            $currentWeekData[] = $contactsByDayWeeks[$currentDay->format('Y-m-d')] ?? 0;
            $previousWeekData[] = $contactsByDayWeeks[$previousDay->format('Y-m-d')] ?? 0;
        }

        // All-time daily series for the contact form (no calls). Returns []
        // if no contact has ever been recorded — the template skips the
        // section in that case so we don't render an empty chart.
        $contactsAllTime = $this->contactRepository->countByDayAllTime();
        $allTimeChartLabels = array_map(
            fn (array $row): string => $this->formatDayLabel(new \DateTimeImmutable($row['date']), $locale),
            $contactsAllTime,
        );
        $allTimeChartData = array_map(static fn (array $row): int => $row['count'], $contactsAllTime);

        // Weekday distribution (Monday → Sunday across all history) for
        // the contact form. Reuses the daily series cached by the repo.
        $contactsByWeekday = $this->contactRepository->countByWeekdayAllTime();
        $weekdayLabels = $this->buildWeekdayLabels($locale);
        $weekdayContactsData = [];
        $weekdayContactsHasData = false;
        for ($i = 1; $i <= 7; ++$i) {
            $count = $contactsByWeekday[$i] ?? 0;
            $weekdayContactsData[] = $count;
            if ($count > 0) {
                $weekdayContactsHasData = true;
            }
        }

        $contactsThisMonth = end($contactsCounts) ?: 0;
        $contactsLastMonth = $contactsCounts[\count($contactsCounts) - 2] ?? 0;
        $callsThisMonth = end($callsCounts) ?: 0;
        $callsLastMonth = $callsCounts[\count($callsCounts) - 2] ?? 0;

        // Year-to-date leads (contacts + calls) by summing buckets that
        // fall in the current civil year. Reuses the 12-month series so
        // no extra repository call.
        $currentYearPrefix = $today->format('Y').'-';
        $thisYearLeads = 0;
        foreach ($contactsByMonth as $bucket) {
            if (str_starts_with($bucket['ym'], $currentYearPrefix)) {
                $thisYearLeads += (int) $bucket['count'];
            }
        }
        foreach ($callsByMonth as $bucket) {
            if (str_starts_with($bucket['ym'], $currentYearPrefix)) {
                $thisYearLeads += (int) $bucket['count'];
            }
        }

        $kpis = [
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.dashboard.kpi.callsLast7d'),
                period: $this->translator->trans('admin.dashboard.kpi.period.last7d'),
                current: $callsLast7,
                previous: $callsPrev7,
            ),
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.dashboard.kpi.contactsLast7d'),
                period: $this->translator->trans('admin.dashboard.kpi.period.last7d'),
                current: $contactsLast7,
                previous: $contactsPrev7,
            ),
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.dashboard.kpi.leadsThisMonth'),
                period: $this->translator->trans('admin.dashboard.kpi.period.thisMonthLeads', [
                    '%month%' => $this->formatMonthLabel($today, $locale),
                ]),
                current: $contactsThisMonth + $callsThisMonth,
                previous: $contactsLastMonth + $callsLastMonth,
                currentLabel: $this->formatMonthNameLabel($today, $locale),
                previousLabel: $this->formatMonthNameLabel($today->modify('first day of this month')->modify('-1 month'), $locale),
            ),
            // Calls API caps at 12 months → no 24-month comparison possible,
            // so this card stays neutral. Renders as a "two stats with
            // divider" (12-month total + year-to-date) in the template.
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.dashboard.kpi.period.summary'),
                period: $this->translator->trans('admin.dashboard.kpi.leadsLast12Months'),
                current: array_sum($contactsCounts) + array_sum($callsCounts),
                previous: null,
            ),
        ];

        return $this->render('admin/dashboard/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'todayLabel' => $this->formatToday($today, $locale),
            'thisYearLeads' => $thisYearLeads,
            'thisYearLabel' => $today->format('Y'),
            'chartLabels' => $chartLabels,
            'chartSeries' => [
                [
                    'label' => $this->translator->trans('admin.dashboard.activity.contactsLabel'),
                    'data' => $contactsCounts,
                    'color' => '#71172e',
                    'fillColor' => 'rgba(113, 23, 46, 0.1)',
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.activity.callsLabel'),
                    'data' => $callsCounts,
                    'color' => '#2563eb',
                    'fillColor' => 'rgba(37, 99, 235, 0.1)',
                ],
            ],
            'weekChartLabels' => $weekDayLabels,
            'weekChartSeries' => [
                [
                    'label' => $this->translator->trans('admin.dashboard.weekVsWeek.previousWeekLabel'),
                    'data' => $previousWeekData,
                    'color' => '#9333ea',
                    'fillColor' => 'rgba(147, 51, 234, 0.1)',
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.weekVsWeek.currentWeekLabel'),
                    'data' => $currentWeekData,
                    'color' => '#16a34a',
                    'fillColor' => 'rgba(22, 163, 74, 0.1)',
                ],
            ],
            'allTimeChartLabels' => $allTimeChartLabels,
            'allTimeChartSeries' => [] === $allTimeChartData ? [] : [
                [
                    'label' => $this->translator->trans('admin.dashboard.contactsAllTime.seriesLabel'),
                    'data' => $allTimeChartData,
                    'color' => '#71172e',
                    'fillColor' => 'rgba(113, 23, 46, 0.1)',
                ],
            ],
            'weekdayChartLabels' => $weekdayLabels,
            'weekdayChartSeries' => $weekdayContactsHasData ? [
                [
                    'label' => $this->translator->trans('admin.dashboard.contactsWeekdays.seriesLabel'),
                    'data' => $weekdayContactsData,
                    'color' => '#14b8a6',
                    'fillColor' => 'rgba(20, 184, 166, 0.1)',
                ],
            ] : [],
            'kpis' => $kpis,
        ]);
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/users/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    /**
     * Resolves a user by its public ULID. The {slug} segment is purely
     * decorative — if it doesn't match the current display slug (e.g. the
     * user was renamed), we 302 to the canonical URL so old links keep
     * working without poisoning bookmarks with a stale slug.
     */
    #[Route(
        '/users/{uniqueId}/{slug}',
        name: 'user_show',
        methods: ['GET'],
        requirements: [
            'uniqueId' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}',
            'slug' => '[a-z0-9-]+',
        ],
    )]
    public function showUser(string $adminPrefix, string $uniqueId, string $slug): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $profile = $this->adminUserRepository->findByUniqueId($uniqueId)
            ?? throw $this->createNotFoundException();

        if ($slug !== $profile->slug) {
            return $this->redirectToRoute('admin_user_show', [
                'adminPrefix' => $adminPrefix,
                'uniqueId' => $uniqueId,
                'slug' => $profile->slug,
            ]);
        }

        return $this->render('admin/users/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'profile' => $profile,
        ]);
    }

    /**
     * @param array<string, int> $byDay
     */
    private function sumDays(array $byDay, \DateTimeImmutable $startInclusive, \DateTimeImmutable $endExclusive): int
    {
        $sum = 0;
        $cursor = $startInclusive;
        while ($cursor < $endExclusive) {
            $sum += $byDay[$cursor->format('Y-m-d')] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        return $sum;
    }

    private function formatToday(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE);

        return ucfirst($formatter->format($date) ?: '');
    }

    private function formatMonthLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM yyyy');

        return ucfirst($formatter->format($date) ?: '');
    }

    private function formatMonthNameLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM');

        return ucfirst($formatter->format($date) ?: '');
    }

    private function formatYmLabel(string $ym, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $ym);
        if (false === $date) {
            return $ym;
        }
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yyyy');

        return $formatter->format($date) ?: $ym;
    }

    private function formatWeekdayLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEE');

        return ucfirst(rtrim((string) $formatter->format($date), '.'));
    }

    private function formatDayLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'd MMM yyyy');

        return ucfirst(rtrim((string) $formatter->format($date), '.'));
    }

    /**
     * Returns localized full weekday names from Monday to Sunday (ISO order).
     * Anchored on a known Monday so we don't depend on the current date.
     *
     * @return list<string>
     */
    private function buildWeekdayLabels(string $locale): array
    {
        $reference = new \DateTimeImmutable('2024-01-01'); // ISO Monday
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEEE');

        $labels = [];
        for ($i = 0; $i < 7; ++$i) {
            $labels[] = ucfirst((string) $formatter->format($reference->modify('+'.$i.' days')));
        }

        return $labels;
    }

    /**
     * Compares the URL prefix to the configured secret in constant time.
     * Throws 404 (not 403) on mismatch to avoid leaking the existence
     * of the admin space on a wrong-but-plausible URL.
     */
    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
