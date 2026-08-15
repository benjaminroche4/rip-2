<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Dossier lifecycle, fully automatic: the status names the step currently
 * pending (the one the staff has to work on), advanced and walked back by
 * DossierStatusAdvancer as steps get validated or reopened. Once the visit
 * step is validated (apartment found), the dossier is in "Finalisation";
 * "closed" is derived from the closure timestamp and never stored.
 */
enum DossierStatus: string
{
    case Persons = 'persons';
    case Search = 'search';
    case File = 'file';
    case Visit = 'visit';
    case Finalization = 'finalization';
    case Closed = 'closed';

    /** Status displayed for a given pending step; null step = path walked. */
    public static function fromPendingStep(?DossierStep $step): self
    {
        return match ($step) {
            DossierStep::Persons => self::Persons,
            DossierStep::Search => self::Search,
            DossierStep::File => self::File,
            DossierStep::Visit => self::Visit,
            // Le paiement clôt le parcours : dès que le bien est trouvé
            // (visite validée), le dossier est en finalisation.
            DossierStep::Payment, null => self::Finalization,
        };
    }

    /** Effective status shown everywhere: closure wins over the step. */
    public static function effective(self $stored, bool $closed): self
    {
        return $closed ? self::Closed : $stored;
    }

    public function labelKey(): string
    {
        return 'admin.dossiers.status.choice.'.$this->value;
    }

    /** Tailwind class of the little colored dot next to the label. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Persons => 'bg-blue-500',
            self::Search => 'bg-emerald-500',
            self::File => 'bg-amber-500',
            self::Visit => 'bg-violet-500',
            self::Finalization => 'bg-green-600',
            self::Closed => 'bg-gray-400',
        };
    }
}
