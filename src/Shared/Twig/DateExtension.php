<?php

namespace App\Shared\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class DateExtension extends AbstractExtension
{
    private const HUMAN_DATE_MAX_DAYS = 30;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('next_monday', [$this, 'getNextMonday']),
            new TwigFunction('format_next_monday', [$this, 'formatNextMonday']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('human_date', [$this, 'humanDate']),
            new TwigFilter('relative_days', [$this, 'relativeDays']),
        ];
    }

    /**
     * Day-level distance to a target date: "Dans 31 jours", "Aujourd'hui",
     * "Il y a 3 jours". Used for planned dates (move-in, recall).
     */
    public function relativeDays(\DateTimeInterface $date): string
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $day = \DateTimeImmutable::createFromInterface($date)->setTimezone($timezone)->setTime(0, 0);
        $today = new \DateTimeImmutable('today', $timezone);

        if ($day == $today) {
            return $this->translator->trans('date.today');
        }

        $days = (int) $today->diff($day)->days;

        return $this->translator->trans($day > $today ? 'date.inDays' : 'date.daysAgo', ['%count%' => $days]);
    }

    /**
     * Compact relative date for admin lists: "Aujourd'hui à 17:04",
     * "Hier à 14:12", "Il y a 3 jours", then plain "12.02.2026" beyond
     * 30 days (a relative count stops being readable past that).
     */
    public function humanDate(\DateTimeInterface $date): string
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $local = \DateTimeImmutable::createFromInterface($date)->setTimezone($timezone);
        $today = new \DateTimeImmutable('today', $timezone);
        $day = $local->setTime(0, 0);

        if ($day == $today) {
            return $this->translator->trans('date.todayAt', ['%time%' => $local->format('H:i')]);
        }
        if ($day == $today->modify('-1 day')) {
            return $this->translator->trans('date.yesterdayAt', ['%time%' => $local->format('H:i')]);
        }

        $days = (int) $day->diff($today)->days;
        if ($day < $today && $days <= self::HUMAN_DATE_MAX_DAYS) {
            return $this->translator->trans('date.daysAgo', ['%count%' => $days]);
        }

        return $local->format('d.m.Y');
    }

    public function getNextMonday(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $dayOfWeek = (int) $now->format('N'); // 1 (Monday) to 7 (Sunday)

        // Si c'est lundi, on prend le lundi prochain (dans 7 jours)
        if (1 === $dayOfWeek) {
            return $now->modify('+7 days');
        }

        // Sinon, on calcule les jours jusqu'au prochain lundi
        $daysUntilMonday = 8 - $dayOfWeek; // 8 - jour actuel = jours jusqu'au lundi

        return $now->modify("+{$daysUntilMonday} days");
    }

    public function formatNextMonday(string $locale = 'fr'): string
    {
        $nextMonday = $this->getNextMonday();

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Europe/Paris',
            \IntlDateFormatter::GREGORIAN,
            'fr' === $locale ? 'EEEE d MMMM' : 'EEEE, MMMM d'
        );

        $formatted = $formatter->format($nextMonday);

        // Capitalise la première lettre
        return mb_convert_case($formatted, MB_CASE_TITLE, 'UTF-8');
    }
}
