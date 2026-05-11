<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\ChartPalette;
use App\Admin\Repository\StripePaymentRepository;
use App\Admin\Service\AdminDateFormatter;
use App\Admin\Service\AdminKpiBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// Same security model as DashboardController: access_control on the prefix
// in security.yaml + hash_equals here. A wrong-but-format-valid prefix
// returns 404 before triggering any auth challenge.
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
final class PaymentsController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
        private readonly StripePaymentRepository $stripePaymentRepository,
        private readonly AdminKpiBuilder $kpiBuilder,
        private readonly AdminDateFormatter $dateFormatter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/paiements',
            'en' => '/payments',
        ],
        name: 'payments',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix, Request $request): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $locale = $request->getLocale();
        $today = new \DateTimeImmutable('today');

        // ── Aggregate data fetches ─────────────────────────────────────
        // The recent payments table itself is rendered by the
        // <twig:Admin:PaymentList /> component, which fetches and paginates
        // on its own. Here we only keep what the cards/charts above need.
        $allTime = $this->stripePaymentRepository->revenueAllTime();
        $monthly = $this->stripePaymentRepository->successfulRevenueByMonth(12);
        $dailyAllTime = $this->stripePaymentRepository->revenueByDayAllTime();

        // 4-week comparison: this month vs prior month, week-by-week.
        // Pull 8 ISO weeks back (4 current + 4 previous) and split.
        $now = new \DateTimeImmutable('now');
        $weeklyFrom = $now->modify('monday this week')->modify('-7 weeks')->setTime(0, 0);
        $weeklyTo = $now->modify('monday this week')->modify('+1 week')->setTime(0, 0);
        $weeklyMap = $this->stripePaymentRepository->successfulRevenueByWeek($weeklyFrom, $weeklyTo);

        // ── Currency resolution ────────────────────────────────────────
        // Pick the first non-empty currency we see across the aggregate
        // sources so the chart labels stay consistent with the cards.
        $currency = $allTime->currency;
        if ('' === $currency) {
            foreach ($monthly as $bucket) {
                if ('' !== $bucket->currency) {
                    $currency = $bucket->currency;
                    break;
                }
            }
        }

        // ── KPI cards ─────────────────────────────────────────────────
        // succeeded-only series indexed by Y-m for fast month lookups.
        $byYm = [];
        foreach ($monthly as $bucket) {
            $byYm[$bucket->ym] = $bucket->totalAmount;
        }
        $thisYm = $today->format('Y-m');
        $lastYm = $today->modify('first day of this month')->modify('-1 month')->format('Y-m');
        $thisMonthAmount = $byYm[$thisYm] ?? 0;
        $lastMonthAmount = $byYm[$lastYm] ?? 0;

        $currencySymbol = $this->currencySymbol($currency);

        // Year-to-date revenue: sum of succeeded buckets falling in the
        // current civil year. Reuses $monthly so no extra API call.
        $currentYearPrefix = $today->format('Y').'-';
        $thisYearAmount = 0;
        foreach ($monthly as $bucket) {
            if (str_starts_with($bucket->ym, $currentYearPrefix)) {
                $thisYearAmount += $bucket->totalAmount;
            }
        }

        // ISO week comparison: current ISO week vs previous ISO week.
        // Reuses the 8-week weeklyMap already fetched above so we don't
        // make an extra API roundtrip for this card.
        $thisWeekStart = $now->modify('monday this week');
        $lastWeekStart = $thisWeekStart->modify('-1 week');
        $thisWeekKey = $thisWeekStart->format('o-W');
        $lastWeekKey = $lastWeekStart->format('o-W');
        $thisWeekAmount = $weeklyMap[$thisWeekKey] ?? 0;
        $lastWeekAmount = $weeklyMap[$lastWeekKey] ?? 0;

        $kpis = [
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.payments.kpi.thisWeek'),
                period: $this->translator->trans('admin.payments.kpi.period.thisWeek', [
                    '%number%' => (int) $thisWeekStart->format('W'),
                ]),
                current: (int) round($thisWeekAmount / 100),
                previous: (int) round($lastWeekAmount / 100),
            ),
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.payments.kpi.thisMonth'),
                period: $this->dateFormatter->monthLabel($today, $locale),
                current: (int) round($thisMonthAmount / 100),
                previous: (int) round($lastMonthAmount / 100),
            ),
            $this->kpiBuilder->build(
                title: $this->translator->trans('admin.payments.kpi.totalAllTime'),
                period: $this->translator->trans('admin.payments.kpi.period.summary'),
                current: (int) round($allTime->totalAmount / 100),
                previous: null,
            ),
        ];

        // ── 12-month chart (succeeded-only, same series as the cards) ──
        // Reuses the same dataset as the KPI lookups above to keep the
        // chart and cards in sync — anything else and a failed payment
        // would inflate the chart bucket without moving the "CA du mois"
        // card, leading to confusing diverging numbers.
        $monthlyChartLabels = [];
        $monthlyChartData = [];
        $monthlyHasData = false;
        foreach ($monthly as $bucket) {
            $monthlyChartLabels[] = $this->dateFormatter->ymLabel($bucket->ym, $locale);
            $monthlyChartData[] = round($bucket->totalAmount / 100, 2);
            if ($bucket->totalAmount > 0) {
                $monthlyHasData = true;
            }
        }

        // ── 4-week comparison chart ────────────────────────────────────
        // 8 weeks pulled, split into [last 4] vs [4 before that]. Labels
        // are the 4 most recent weeks. The "previous" series shifts each
        // value by 4 weeks for an apples-to-apples per-position compare.
        $weekLabels = [];
        $weekCurrent = [];
        $weekPrevious = [];
        $weekCurrentTooltips = [];
        $weekPreviousTooltips = [];
        $weeklyHasData = false;
        for ($i = 3; $i >= 0; --$i) {
            $current = $now->modify('monday this week')->modify('-'.$i.' weeks');
            $previous = $current->modify('-4 weeks');
            $cKey = $current->format('o-W');
            $pKey = $previous->format('o-W');
            $cAmount = round(($weeklyMap[$cKey] ?? 0) / 100, 2);
            $pAmount = round(($weeklyMap[$pKey] ?? 0) / 100, 2);
            $cLabel = $this->translator->trans('admin.payments.weekly.weekLabel', [
                '%number%' => (int) $current->format('W'),
            ]);
            $pLabel = $this->translator->trans('admin.payments.weekly.weekLabel', [
                '%number%' => (int) $previous->format('W'),
            ]);
            $weekLabels[] = $cLabel;
            $weekCurrent[] = $cAmount;
            $weekPrevious[] = $pAmount;
            $weekCurrentTooltips[] = $cLabel;
            $weekPreviousTooltips[] = $pLabel;
            if ($cAmount > 0 || $pAmount > 0) {
                $weeklyHasData = true;
            }
        }

        // ── This-month vs last-month daily chart ──────────────────────
        // Reuses $dailyAllTime (same cache as the all-time chart) and
        // slices it into two day-of-month-aligned series. Future days of
        // the current month are null so the line stops at "today" instead
        // of dropping to zero across the rest of the month.
        $dailyByDate = [];
        foreach ($dailyAllTime as $row) {
            $dailyByDate[$row['date']] = (int) $row['amount'];
        }
        $curMonthStart = $today->modify('first day of this month');
        $prevMonthStart = $curMonthStart->modify('-1 month');
        $daysInCurrent = (int) $curMonthStart->format('t');
        $daysInPrevious = (int) $prevMonthStart->format('t');
        $daysMax = max($daysInCurrent, $daysInPrevious);
        $todayDay = (int) $today->format('j');
        $monthlyDailyLabels = [];
        $monthlyDailyCurrent = [];
        $monthlyDailyPrevious = [];
        $monthlyDailyHasData = false;
        for ($d = 1; $d <= $daysMax; ++$d) {
            $monthlyDailyLabels[] = (string) $d;

            if ($d > $daysInCurrent || $d > $todayDay) {
                $monthlyDailyCurrent[] = null;
            } else {
                $key = $curMonthStart->modify('+'.($d - 1).' days')->format('Y-m-d');
                $amount = round(($dailyByDate[$key] ?? 0) / 100, 2);
                $monthlyDailyCurrent[] = $amount;
                if ($amount > 0) {
                    $monthlyDailyHasData = true;
                }
            }

            if ($d > $daysInPrevious) {
                $monthlyDailyPrevious[] = null;
            } else {
                $key = $prevMonthStart->modify('+'.($d - 1).' days')->format('Y-m-d');
                $amount = round(($dailyByDate[$key] ?? 0) / 100, 2);
                $monthlyDailyPrevious[] = $amount;
                if ($amount > 0) {
                    $monthlyDailyHasData = true;
                }
            }
        }

        // ── All-time daily chart (succeeded only, contiguous series) ───
        $allTimeChartLabels = array_map(
            fn (array $row): string => $this->dateFormatter->dayLabel(new \DateTimeImmutable($row['date']), $locale),
            $dailyAllTime,
        );
        $allTimeChartData = array_map(static fn (array $row): float => round($row['amount'] / 100, 2), $dailyAllTime);

        // ── Weekday distribution (Monday → Sunday across all history) ──
        // Reuses the daily series above; no extra API call.
        $byWeekday = $this->stripePaymentRepository->successfulRevenueByWeekday();
        $weekdayLabels = $this->dateFormatter->weekdayNames($locale);
        $weekdayData = [];
        $weekdayHasData = false;
        for ($i = 1; $i <= 7; ++$i) {
            $amount = round(($byWeekday[$i] ?? 0) / 100, 2);
            $weekdayData[] = $amount;
            if ($amount > 0) {
                $weekdayHasData = true;
            }
        }

        return $this->render('admin/payments/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'currency' => $currency,
            'currencySymbol' => $currencySymbol,
            'thisYearAmount' => (int) round($thisYearAmount / 100),
            'thisYearLabel' => $today->format('Y'),
            'kpis' => $kpis,
            'monthlyChartLabels' => $monthlyChartLabels,
            'monthlyChartSeries' => $monthlyHasData ? [
                [
                    'label' => $this->translator->trans('admin.payments.charts.monthly.seriesLabel'),
                    'data' => $monthlyChartData,
                    ...ChartPalette::SKY,
                ],
            ] : [],
            'monthlyDailyChartLabels' => $monthlyDailyLabels,
            'monthlyDailyChartSeries' => $monthlyDailyHasData ? [
                [
                    'label' => $this->dateFormatter->monthName($prevMonthStart, $locale),
                    'data' => $monthlyDailyPrevious,
                    ...ChartPalette::PINK,
                ],
                [
                    'label' => $this->dateFormatter->monthName($curMonthStart, $locale),
                    'data' => $monthlyDailyCurrent,
                    ...ChartPalette::INDIGO,
                ],
            ] : [],
            'allTimeChartLabels' => $allTimeChartLabels,
            'allTimeChartSeries' => [] === $allTimeChartData ? [] : [
                [
                    'label' => $this->translator->trans('admin.payments.charts.allTime.seriesLabel'),
                    'data' => $allTimeChartData,
                    ...ChartPalette::SKY,
                ],
            ],
            'weekdayChartLabels' => $weekdayLabels,
            'weekdayChartSeries' => $weekdayHasData ? [
                [
                    'label' => $this->translator->trans('admin.payments.charts.weekdays.seriesLabel'),
                    'data' => $weekdayData,
                    ...ChartPalette::EMERALD,
                ],
            ] : [],
            'weeklyChartLabels' => $weekLabels,
            'thisWeekNumber' => (int) $thisWeekStart->format('W'),
            'weeklyChartSeries' => $weeklyHasData ? [
                [
                    'label' => $this->translator->trans('admin.payments.weekly.previousLabel'),
                    'data' => $weekPrevious,
                    'tooltipLabels' => $weekPreviousTooltips,
                    ...ChartPalette::PINK,
                ],
                [
                    'label' => $this->translator->trans('admin.payments.weekly.currentLabel'),
                    'data' => $weekCurrent,
                    'tooltipLabels' => $weekCurrentTooltips,
                    ...ChartPalette::INDIGO,
                ],
            ] : [],
        ]);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Maps an ISO 4217 currency code to its display suffix. Falls back to
     * the raw code for currencies without a universally recognized symbol
     * (CHF, NOK, SEK…) since "1 000 CHF" reads better than "1 000 ₣".
     */
    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            '' => '',
            default => $currency,
        };
    }
}
