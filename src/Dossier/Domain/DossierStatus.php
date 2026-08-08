<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Dossier lifecycle, semi-automatic: the operator only picks between the
 * manual statuses (new / searching / property found); "awaiting documents"
 * and "closed" are derived from the dossier state and never stored.
 */
enum DossierStatus: string
{
    case New = 'new';
    case AwaitingDocuments = 'awaiting_documents';
    case Searching = 'searching';
    case PropertyFound = 'property_found';
    case Closed = 'closed';

    /**
     * The statuses an operator can pick, in pipeline order.
     *
     * @return list<self>
     */
    public static function manualCases(): array
    {
        return [self::New, self::Searching, self::PropertyFound];
    }

    public function isManual(): bool
    {
        return \in_array($this, self::manualCases(), true);
    }

    /**
     * Effective status shown everywhere: closure wins, then incomplete
     * pieces, then the operator's manual choice.
     */
    public static function effective(self $manual, bool $closed, bool $hasPendingDocuments): self
    {
        return match (true) {
            $closed => self::Closed,
            $hasPendingDocuments => self::AwaitingDocuments,
            default => $manual,
        };
    }

    public function labelKey(): string
    {
        return 'admin.dossiers.status.choice.'.$this->value;
    }

    /** Tailwind class of the little colored dot next to the label. */
    public function dotClass(): string
    {
        return match ($this) {
            self::New => 'bg-blue-500',
            self::AwaitingDocuments => 'bg-amber-500',
            self::Searching => 'bg-emerald-500',
            self::PropertyFound => 'bg-violet-500',
            self::Closed => 'bg-gray-400',
        };
    }
}
