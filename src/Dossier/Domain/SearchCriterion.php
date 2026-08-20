<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Contact\Domain\Furnishing;
use App\Contact\Domain\GuarantorType;
use App\Contact\Domain\StayDuration;
use App\Dossier\Entity\DossierSearch;
use App\PropertyListing\Domain\Amenity;

/**
 * Chip-driven criteria of the dossier search card: each case knows its
 * allowed values, whether it is a multi-select (CSV) or a single-select
 * chip, and how to read/write its column on the DossierSearch snapshot.
 */
enum SearchCriterion: string
{
    case StayDuration = 'stayDuration';
    case Furnishing = 'furnishing';
    case GuarantorType = 'guarantorType';
    case GuarantorStatus = 'guarantorStatus';
    case Equipment = 'equipment';
    case LeaseTypes = 'leaseTypes';
    case Pets = 'pets';
    case EarlyMoveIn = 'earlyMoveIn';
    case Elevator = 'elevator';
    case GroundFloor = 'groundFloor';
    case TopFloor = 'topFloor';
    case Parking = 'parking';
    case Occupants = 'occupants';
    case MinBedrooms = 'minBedrooms';
    case HouseholdType = 'householdType';

    /** Progress of the guarantee, clear labels in translations. */
    public const GUARANTOR_STATUSES = ['not_started', 'in_progress', 'obtained', 'refused'];

    /** Allowed values of the optional yes/no criteria (pets, elevator...). */
    public const YES_NO = ['yes', 'no'];

    /** Household typologies of the project, clear labels in translations. */
    public const HOUSEHOLD_TYPES = ['alone', 'couple', 'family', 'flatshare', 'other'];

    /** Desired lease types (French rental market), multi-select. */
    public const LEASE_TYPES = ['civil_code', 'alur', 'mobility'];

    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return match ($this) {
            self::StayDuration => array_map(static fn (StayDuration $case): string => $case->value, StayDuration::cases()),
            self::Furnishing => array_map(static fn (Furnishing $case): string => $case->value, Furnishing::cases()),
            self::GuarantorType => array_map(static fn (GuarantorType $case): string => $case->value, GuarantorType::cases()),
            self::GuarantorStatus => self::GUARANTOR_STATUSES,
            self::Equipment => array_map(static fn (Amenity $case): string => $case->value, Amenity::cases()),
            self::LeaseTypes => self::LEASE_TYPES,
            self::Pets, self::EarlyMoveIn, self::Elevator, self::GroundFloor, self::TopFloor, self::Parking => self::YES_NO,
            self::Occupants => ['1', '2', '3', '4', '5', '6'],
            self::MinBedrooms => ['1', '2', '3', '4'],
            self::HouseholdType => self::HOUSEHOLD_TYPES,
        };
    }

    /** Multi-select criteria persist several values; a tenant can pick many. */
    public function isMulti(): bool
    {
        return match ($this) {
            self::Furnishing, self::GuarantorType, self::Equipment, self::LeaseTypes => true,
            default => false,
        };
    }

    /** Stored value as a string ('' when unset), ints included. */
    public function current(DossierSearch $search): string
    {
        return match ($this) {
            self::StayDuration => (string) $search->getStayDuration(),
            self::Furnishing => (string) $search->getFurnishing(),
            self::GuarantorType => implode(',', $search->getGuarantorTypes()),
            self::GuarantorStatus => (string) $search->getGuarantorStatus(),
            self::Equipment => (string) $search->getEquipment(),
            self::LeaseTypes => (string) $search->getLeaseTypes(),
            self::Pets => (string) $search->getPets(),
            self::EarlyMoveIn => (string) $search->getEarlyMoveIn(),
            self::Elevator => (string) $search->getElevator(),
            self::GroundFloor => (string) $search->getGroundFloor(),
            self::TopFloor => (string) $search->getTopFloor(),
            self::Parking => (string) $search->getParking(),
            self::Occupants => null !== $search->getOccupants() ? (string) $search->getOccupants() : '',
            self::MinBedrooms => null !== $search->getMinBedrooms() ? (string) $search->getMinBedrooms() : '',
            self::HouseholdType => (string) $search->getHouseholdType(),
        };
    }

    public function write(DossierSearch $search, ?string $value): void
    {
        match ($this) {
            self::StayDuration => $search->setStayDuration($value),
            self::Furnishing => $search->setFurnishing($value),
            self::GuarantorType => $search->setGuarantorTypes(null !== $value ? CsvSelection::values($value) : null),
            self::GuarantorStatus => $search->setGuarantorStatus($value),
            self::Equipment => $search->setEquipment($value),
            self::LeaseTypes => $search->setLeaseTypes($value),
            self::Pets => $search->setPets($value),
            self::EarlyMoveIn => $search->setEarlyMoveIn($value),
            self::Elevator => $search->setElevator($value),
            self::GroundFloor => $search->setGroundFloor($value),
            self::TopFloor => $search->setTopFloor($value),
            self::Parking => $search->setParking($value),
            self::Occupants => $search->setOccupants(null !== $value ? (int) $value : null),
            self::MinBedrooms => $search->setMinBedrooms(null !== $value ? (int) $value : null),
            self::HouseholdType => $search->setHouseholdType($value),
        };
    }

    /** 400 message for a value outside the whitelist (stale/forged DOM). */
    public function invalidMessage(string|int $value): string
    {
        return match ($this) {
            self::StayDuration => \sprintf('Unknown stay duration "%s".', $value),
            self::Furnishing => \sprintf('Unknown furnishing "%s".', $value),
            self::GuarantorType => \sprintf('Unknown guarantor type "%s".', $value),
            self::GuarantorStatus => \sprintf('Unknown guarantor status "%s".', $value),
            self::Equipment => \sprintf('Unknown equipment "%s".', $value),
            self::LeaseTypes => \sprintf('Unknown lease type "%s".', $value),
            self::Pets => \sprintf('Unknown pets value "%s".', $value),
            self::EarlyMoveIn => \sprintf('Unknown early move-in value "%s".', $value),
            self::Elevator => \sprintf('Unknown elevator value "%s".', $value),
            self::GroundFloor => \sprintf('Unknown ground floor value "%s".', $value),
            self::TopFloor => \sprintf('Unknown top floor value "%s".', $value),
            self::Parking => \sprintf('Unknown parking value "%s".', $value),
            self::Occupants => \sprintf('Invalid occupants count "%d".', $value),
            self::MinBedrooms => \sprintf('Invalid bedroom count "%d".', $value),
            self::HouseholdType => \sprintf('Unknown household type "%s".', $value),
        };
    }
}
