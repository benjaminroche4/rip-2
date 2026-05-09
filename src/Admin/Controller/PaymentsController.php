<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\StripePaymentRepository;
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
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/payments', name: 'payments', methods: ['GET'])]
    public function index(string $adminPrefix, Request $request): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $locale = $request->getLocale();
        $today = new \DateTimeImmutable('today');

        // ── Aggregate data fetches ─────────────────────────────────────
        $allTime = $this->stripePaymentRepository->revenueAllTime();
        $monthly = $this->stripePaymentRepository->successfulRevenueByMonth(12);
        $recent = $this->stripePaymentRepository->recentPayments(100);
        $dailyAllTime = $this->stripePaymentRepository->revenueByDayAllTime();

        // 4-week comparison: this month vs prior month, week-by-week.
        // Pull 8 ISO weeks back (4 current + 4 previous) and split.
        $now = new \DateTimeImmutable('now');
        $weeklyFrom = $now->modify('monday this week')->modify('-7 weeks')->setTime(0, 0);
        $weeklyTo = $now->modify('monday this week')->modify('+1 week')->setTime(0, 0);
        $weeklyMap = $this->stripePaymentRepository->successfulRevenueByWeek($weeklyFrom, $weeklyTo);

        // ── Currency resolution ────────────────────────────────────────
        // Pick the first non-empty currency we see across data sources so
        // the chart label is consistent with the cards.
        $currency = $allTime->currency;
        if ('' === $currency) {
            foreach ($monthly as $bucket) {
                if ('' !== $bucket->currency) {
                    $currency = $bucket->currency;
                    break;
                }
            }
        }
        if ('' === $currency && [] !== $recent) {
            $currency = $recent[0]->currency;
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
                period: $this->formatMonthLabel($today, $locale),
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
            $monthlyChartLabels[] = $this->formatYmLabel($bucket->ym, $locale);
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
        $weeklyHasData = false;
        for ($i = 3; $i >= 0; --$i) {
            $current = $now->modify('monday this week')->modify('-'.$i.' weeks');
            $previous = $current->modify('-4 weeks');
            $cKey = $current->format('o-W');
            $pKey = $previous->format('o-W');
            $cAmount = round(($weeklyMap[$cKey] ?? 0) / 100, 2);
            $pAmount = round(($weeklyMap[$pKey] ?? 0) / 100, 2);
            $weekLabels[] = $this->translator->trans('admin.payments.weekly.weekLabel', [
                '%number%' => (int) $current->format('W'),
            ]);
            $weekCurrent[] = $cAmount;
            $weekPrevious[] = $pAmount;
            if ($cAmount > 0 || $pAmount > 0) {
                $weeklyHasData = true;
            }
        }

        // ── All-time daily chart (succeeded only, contiguous series) ───
        $allTimeChartLabels = array_map(
            fn (array $row): string => $this->formatDayLabel(new \DateTimeImmutable($row['date']), $locale),
            $dailyAllTime,
        );
        $allTimeChartData = array_map(static fn (array $row): float => round($row['amount'] / 100, 2), $dailyAllTime);

        // ── Weekday distribution (Monday → Sunday across all history) ──
        // Reuses the daily series above; no extra API call.
        $byWeekday = $this->stripePaymentRepository->successfulRevenueByWeekday();
        $weekdayLabels = $this->buildWeekdayLabels($locale);
        $weekdayData = [];
        $weekdayHasData = false;
        for ($i = 1; $i <= 7; ++$i) {
            $amount = round(($byWeekday[$i] ?? 0) / 100, 2);
            $weekdayData[] = $amount;
            if ($amount > 0) {
                $weekdayHasData = true;
            }
        }

        // ── Table rows ─────────────────────────────────────────────────
        $tableRows = array_map(function ($row) use ($locale): array {
            return [
                'id' => $row->id,
                'status' => $row->status,
                'statusLabel' => $this->translator->trans('admin.payments.status.'.$row->status),
                'amount' => round($row->amount / 100, 2),
                'currency' => $row->currency,
                'currencySymbol' => $this->currencySymbol($row->currency),
                'customerName' => $row->customerName,
                'customerEmail' => $row->customerEmail,
                'createdAtLabel' => $this->formatDateTimeLabel($row->createdAt, $locale),
            ];
        }, $recent);

        return $this->render('admin/payments/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'todayLabel' => $this->formatToday($today, $locale),
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
                    'color' => '#0ea5e9',
                    'fillColor' => 'rgba(14, 165, 233, 0.1)',
                ],
            ] : [],
            'allTimeChartLabels' => $allTimeChartLabels,
            'allTimeChartSeries' => [] === $allTimeChartData ? [] : [
                [
                    'label' => $this->translator->trans('admin.payments.charts.allTime.seriesLabel'),
                    'data' => $allTimeChartData,
                    'color' => '#0ea5e9',
                    'fillColor' => 'rgba(14, 165, 233, 0.1)',
                ],
            ],
            'weekdayChartLabels' => $weekdayLabels,
            'weekdayChartSeries' => $weekdayHasData ? [
                [
                    'label' => $this->translator->trans('admin.payments.charts.weekdays.seriesLabel'),
                    'data' => $weekdayData,
                    'color' => '#10b981',
                    'fillColor' => 'rgba(16, 185, 129, 0.1)',
                ],
            ] : [],
            'weeklyChartLabels' => $weekLabels,
            'thisWeekNumber' => (int) $thisWeekStart->format('W'),
            'weeklyChartSeries' => $weeklyHasData ? [
                [
                    'label' => $this->translator->trans('admin.payments.weekly.previousLabel'),
                    'data' => $weekPrevious,
                    'color' => '#ec4899',
                    'fillColor' => 'rgba(236, 72, 153, 0.1)',
                ],
                [
                    'label' => $this->translator->trans('admin.payments.weekly.currentLabel'),
                    'data' => $weekCurrent,
                    'color' => '#6366f1',
                    'fillColor' => 'rgba(99, 102, 241, 0.1)',
                ],
            ] : [],
            'tableRows' => $tableRows,
            'tableEmpty' => [] === $tableRows,
            'stripeDashboardUrl' => 'https://dashboard.stripe.com/payments',
        ]);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
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

    private function formatDateTimeLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'd MMM yyyy, HH:mm');

        return $formatter->format($date) ?: $date->format('Y-m-d H:i');
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
