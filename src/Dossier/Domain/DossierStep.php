<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Steps of the dossier, in the order the staff walks them from left to
 * right. A step unlocks only once every step before it is validated, so the
 * tab bar reads as a path rather than as six independent screens.
 *
 * The "recap" tab is deliberately absent: it is an overview of the whole
 * dossier, always reachable, never a step to validate.
 */
enum DossierStep: string
{
    case Persons = 'persons';
    case Search = 'search';
    case File = 'file';
    case Visit = 'visit';
    case Payment = 'payment';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [self::Persons, self::Search, self::File, self::Visit, self::Payment];
    }

    /** 1-based rank, shown in front of the tab label. */
    public function rank(): int
    {
        return array_search($this, self::ordered(), true) + 1;
    }

    public function previous(): ?self
    {
        return self::ordered()[$this->rank() - 2] ?? null;
    }

    public function next(): ?self
    {
        return self::ordered()[$this->rank()] ?? null;
    }

    public function labelKey(): string
    {
        return 'admin.dossiers.show.steps.'.$this->value.'.label';
    }

    /** Sentence telling what to do to validate the step. */
    public function todoKey(): string
    {
        return 'admin.dossiers.show.steps.'.$this->value.'.todo';
    }

    public function icon(): string
    {
        return match ($this) {
            self::Persons => 'lucide:users',
            self::Search => 'lucide:search',
            self::File => 'lucide:folder-open',
            self::Visit => 'lucide:calendar-check',
            self::Payment => 'lucide:credit-card',
        };
    }

}
