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

        $contactsByMonth = $this->contactRepository->countByMonth(12);
        $callsByMonth = $this->callRepository->countByMonth(12);

        $contactsCounts = array_map(static fn (array $row): int => $row['count'], $contactsByMonth);
        $callsCounts = array_map(static fn (array $row): int => $row['count'], $callsByMonth);

        $labels = array_map(
            fn (array $row): string => $this->formatYmLabel($row['ym'], $request->getLocale()),
            $contactsByMonth,
        );

        $weeklyComparison = $this->buildWeeklyComparison($request->getLocale());

        return $this->render('admin/dashboard/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'todayLabel' => $this->formatToday($request->getLocale()),
            'chartLabels' => $labels,
            'chartSeries' => [
                [
                    'label' => $this->translator->trans('admin.dashboard.activity.contactsLabel'),
                    'data' => $contactsCounts,
                    'color' => '#71172e',                       // primary (wine red)
                    'fillColor' => 'rgba(113, 23, 46, 0.1)',    // primary @ 10% — sheer wash, lets the other series show through
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.activity.callsLabel'),
                    'data' => $callsCounts,
                    'color' => '#2563eb',                       // blue-600
                    'fillColor' => 'rgba(37, 99, 235, 0.1)',    // blue-600 @ 10%
                ],
            ],
            'stats' => [
                'contactsThisMonth' => end($contactsCounts) ?: 0,
                'contactsTotal' => array_sum($contactsCounts),
                'callsThisMonth' => end($callsCounts) ?: 0,
                'callsTotal' => array_sum($callsCounts),
                ...$this->summarizeWeek($weeklyComparison),
            ],
            'weeklyComparison' => $weeklyComparison,
        ]);
    }

    /**
     * Aggregates the week-so-far totals + deltas vs last week. Used in the
     * hero section's "this week" highlights. Future days are excluded from
     * both the totals and the deltas (we don't pretend they happened).
     *
     * @param list<array{
     *     dayLabel: string,
     *     isFuture: bool,
     *     contacts: array{thisWeek: ?int, lastWeek: int, delta: ?int},
     *     calls: array{thisWeek: ?int, lastWeek: int, delta: ?int},
     * }> $rows
     *
     * @return array{
     *     contactsThisWeek: int,
     *     contactsThisWeekDelta: int,
     *     callsThisWeek: int,
     *     callsThisWeekDelta: int,
     * }
     */
    private function summarizeWeek(array $rows): array
    {
        $totals = ['contactsThisWeek' => 0, 'contactsThisWeekDelta' => 0, 'callsThisWeek' => 0, 'callsThisWeekDelta' => 0];
        foreach ($rows as $row) {
            if ($row['isFuture']) {
                continue;
            }
            $totals['contactsThisWeek'] += $row['contacts']['thisWeek'] ?? 0;
            $totals['contactsThisWeekDelta'] += $row['contacts']['delta'] ?? 0;
            $totals['callsThisWeek'] += $row['calls']['thisWeek'] ?? 0;
            $totals['callsThisWeekDelta'] += $row['calls']['delta'] ?? 0;
        }

        return $totals;
    }

    /**
     * "Lundi 27 avril 2026" (fr) / "Monday, 27 April 2026" (en).
     */
    private function formatToday(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE);

        return ucfirst($formatter->format(new \DateTimeImmutable('today')) ?: '');
    }

    /**
     * Builds the 7-day Mon→Sun matrix comparing this week to the previous
     * one for both contacts and calls. Future days (this week's days that
     * haven't happened yet) carry `thisWeek = null` so the template can
     * render an em dash.
     *
     * @return list<array{
     *     dayLabel: string,
     *     isFuture: bool,
     *     contacts: array{thisWeek: ?int, lastWeek: int, delta: ?int},
     *     calls: array{thisWeek: ?int, lastWeek: int, delta: ?int},
     * }>
     */
    private function buildWeeklyComparison(string $locale): array
    {
        $today = new \DateTimeImmutable('today');
        $thisWeekMonday = $today->modify('-'.((int) $today->format('N') - 1).' days');
        $lastWeekMonday = $thisWeekMonday->modify('-7 days');

        // Pull both weeks at once: from last Monday (inclusive) to next
        // Monday (exclusive). 14 days of data, two SQL/cache hits at most.
        $from = $lastWeekMonday;
        $to = $thisWeekMonday->modify('+7 days');

        $contactsByDay = $this->contactRepository->countByDay($from, $to);
        $callsByDay = $this->callRepository->countByDay($from, $to);

        $dayFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEE');

        $rows = [];
        for ($i = 0; $i < 7; ++$i) {
            $thisDate = $thisWeekMonday->modify('+'.$i.' days');
            $lastDate = $lastWeekMonday->modify('+'.$i.' days');
            $isFuture = $thisDate > $today;

            $thisContacts = $isFuture ? null : ($contactsByDay[$thisDate->format('Y-m-d')] ?? 0);
            $lastContacts = $contactsByDay[$lastDate->format('Y-m-d')] ?? 0;

            $thisCalls = $isFuture ? null : ($callsByDay[$thisDate->format('Y-m-d')] ?? 0);
            $lastCalls = $callsByDay[$lastDate->format('Y-m-d')] ?? 0;

            $rows[] = [
                'dayLabel' => $dayFormatter->format($thisDate) ?: $thisDate->format('D'),
                'isFuture' => $isFuture,
                'contacts' => [
                    'thisWeek' => $thisContacts,
                    'lastWeek' => $lastContacts,
                    'delta' => null === $thisContacts ? null : $thisContacts - $lastContacts,
                ],
                'calls' => [
                    'thisWeek' => $thisCalls,
                    'lastWeek' => $lastCalls,
                    'delta' => null === $thisCalls ? null : $thisCalls - $lastCalls,
                ],
            ];
        }

        return $rows;
    }

    /**
     * "2026-04" → "avril 2026" (fr) / "April 2026" (en).
     */
    private function formatYmLabel(string $ym, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $ym);
        if (false === $date) {
            return $ym;
        }
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yyyy');

        return $formatter->format($date) ?: $ym;
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
