<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DateExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('next_monday', [$this, 'getNextMonday']),
            new TwigFunction('format_next_monday', [$this, 'formatNextMonday']),
        ];
    }

    public function getNextMonday(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $dayOfWeek = (int) $now->format('N'); // 1 (Monday) to 7 (Sunday)

        // Si c'est lundi, on prend le lundi prochain (dans 7 jours)
        if ($dayOfWeek === 1) {
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
            $locale === 'fr' ? 'EEEE d MMMM' : 'EEEE, MMMM d'
        );

        $formatted = $formatter->format($nextMonday);

        // Capitalise la première lettre
        return mb_convert_case($formatted, MB_CASE_TITLE, "UTF-8");
    }
}
