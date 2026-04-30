<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CallRepository;
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

        $contactsThisMonth = end($contactsCounts) ?: 0;
        $contactsLastMonth = $contactsCounts[\count($contactsCounts) - 2] ?? 0;
        $callsThisMonth = end($callsCounts) ?: 0;
        $callsLastMonth = $callsCounts[\count($callsCounts) - 2] ?? 0;

        $kpis = [
            $this->buildKpi(
                title: $this->translator->trans('admin.dashboard.kpi.callsLast7d'),
                period: $this->translator->trans('admin.dashboard.kpi.period.last7d'),
                current: $callsLast7,
                previous: $callsPrev7,
            ),
            $this->buildKpi(
                title: $this->translator->trans('admin.dashboard.kpi.contactsLast7d'),
                period: $this->translator->trans('admin.dashboard.kpi.period.last7d'),
                current: $contactsLast7,
                previous: $contactsPrev7,
            ),
            $this->buildKpi(
                title: $this->translator->trans('admin.dashboard.kpi.leadsThisMonth'),
                period: $this->formatMonthLabel($today, $locale),
                current: $contactsThisMonth + $callsThisMonth,
                previous: $contactsLastMonth + $callsLastMonth,
            ),
            // Calls API caps at 12 months → no 24-month comparison possible,
            // so this card stays neutral (no trend, no arrow).
            $this->buildKpi(
                title: $this->translator->trans('admin.dashboard.kpi.leadsLast12Months'),
                period: $this->translator->trans('admin.dashboard.kpi.period.last12m'),
                current: array_sum($contactsCounts) + array_sum($callsCounts),
                previous: null,
            ),
        ];

        return $this->render('admin/dashboard/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'todayLabel' => $this->formatToday($today, $locale),
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
                    'fillColor' => '#faf5ff',
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.weekVsWeek.currentWeekLabel'),
                    'data' => $currentWeekData,
                    'color' => '#16a34a',
                    'fillColor' => '#f0fdf4',
                ],
            ],
            'kpis' => $kpis,
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

    /**
     * Builds a KPI card payload comparing $current to $previous. When
     * $previous is null, the card stays neutral (no comparison data
     * available — e.g. periods that fall outside our 12-month window).
     *
     * @return array{title:string, period:string, value:int, previous:?int, deltaPercent:?int, trend:'up'|'down'|'neutral'}
     */
    private function buildKpi(string $title, string $period, int $current, ?int $previous): array
    {
        $trend = 'neutral';
        $deltaPercent = null;

        if (null !== $previous) {
            if ($previous > 0) {
                $deltaPercent = (int) round((($current - $previous) / $previous) * 100);
                $trend = $deltaPercent > 0 ? 'up' : ($deltaPercent < 0 ? 'down' : 'neutral');
            } elseif ($current > 0) {
                // 0 → N: real growth, but % is undefined. The template
                // shows an arrow without a percentage in that case.
                $trend = 'up';
            }
        }

        return [
            'title' => $title,
            'period' => $period,
            'value' => $current,
            'previous' => $previous,
            'deltaPercent' => $deltaPercent,
            'trend' => $trend,
        ];
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
