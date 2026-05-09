<?php

declare(strict_types=1);

namespace App\Admin\Service;

/**
 * Localized date/time labels for admin charts and KPI cards. Centralizes the
 * IntlDateFormatter patterns shared between DashboardController and
 * PaymentsController so the two pages stay typographically consistent
 * (e.g. capitalized month names, no trailing dot on abbreviated days).
 */
final readonly class AdminDateFormatter
{
    /**
     * Long localized today label, e.g. "Vendredi 9 mai 2026".
     */
    public function today(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE);

        return ucfirst($formatter->format($date) ?: '');
    }

    /**
     * Month + year, e.g. "Mai 2026". Used for KPI period subtitles.
     */
    public function monthLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM yyyy');

        return ucfirst($formatter->format($date) ?: '');
    }

    /**
     * Plain month name, e.g. "Mai". Used as the bar label of comparison
     * KPI cards (current vs previous month).
     */
    public function monthName(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM');

        return ucfirst($formatter->format($date) ?: '');
    }

    /**
     * Abbreviated month + year from a Y-m string, e.g. "Mai 2026". Used for
     * the 12-month chart x-axis.
     */
    public function ymLabel(string $ym, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $ym);
        if (false === $date) {
            return $ym;
        }
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yyyy');

        return $formatter->format($date) ?: $ym;
    }

    /**
     * Day + abbreviated month + year, e.g. "9 mai 2026". Used for the
     * all-time daily chart x-axis. Trailing dot is stripped because some
     * locales emit "9 mai." which reads weirdly in a tick label.
     */
    public function dayLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'd MMM yyyy');

        return ucfirst(rtrim((string) $formatter->format($date), '.'));
    }

    /**
     * Abbreviated weekday label, e.g. "Lun" / "Mon". Used for the
     * week-vs-week chart.
     */
    public function weekdayLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEE');

        return ucfirst(rtrim((string) $formatter->format($date), '.'));
    }

    /**
     * Returns localized full weekday names from Monday to Sunday (ISO order).
     * Anchored on a known Monday so we don't depend on the current date.
     *
     * @return list<string>
     */
    public function weekdayNames(string $locale): array
    {
        $reference = new \DateTimeImmutable('2024-01-01'); // ISO Monday
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEEE');

        $labels = [];
        for ($i = 0; $i < 7; ++$i) {
            $labels[] = ucfirst((string) $formatter->format($reference->modify('+'.$i.' days')));
        }

        return $labels;
    }
}
