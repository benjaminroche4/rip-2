<?php

namespace App\PropertyListing\Domain;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PropertyListingSubmission
{
    public ?string $fullName = null;

    public ?string $email = null;

    public ?string $phoneNumber = null;

    public ?string $address = null;

    /** Google Places id of the picked suggestion, proves the address came from the list. */
    public ?string $placeId = null;

    public ?PropertyType $propertyType = null;

    public ?PropertyStatus $propertyStatus = null;

    public ?int $bedrooms = null;

    public ?int $bathrooms = null;

    public ?int $surface = null;

    public ?Furnishing $furnishing = null;

    public ?int $floor = null;

    #[Assert\GreaterThanOrEqual(propertyPath: 'floor', message: 'listProperty.form.buildingFloors.belowFloor')]
    public ?int $buildingFloors = null;

    /** @var list<Orientation> */
    public array $orientations = [];

    /** @var list<LeaseType> */
    public array $leaseTypes = [];

    public ?int $rent = null;

    public ?int $charges = null;

    public ?int $deposit = null;

    /** @var list<Amenity> */
    public array $amenities = [];

    public ?string $note = null;

    /**
     * A house has no floor inside a building: the floor fields are hidden
     * client-side and optional here. Any other property type keeps them
     * required.
     */
    #[Assert\Callback]
    public function validateFloors(ExecutionContextInterface $context): void
    {
        if (PropertyType::House === $this->propertyType) {
            return;
        }

        if (null === $this->floor) {
            $context->buildViolation('listProperty.form.floor.notBlank')->atPath('floor')->addViolation();
        }
        if (null === $this->buildingFloors) {
            $context->buildViolation('listProperty.form.buildingFloors.notBlank')->atPath('buildingFloors')->addViolation();
        }
    }

    /**
     * An Airbnb-only project has no monthly rent, charges or deposit: the
     * financial fields are hidden client-side and optional here. Any other
     * lease type keeps them required.
     */
    #[Assert\Callback]
    public function validateFinancials(ExecutionContextInterface $context): void
    {
        if ($this->isAirbnbOnly()) {
            return;
        }

        foreach (['rent', 'charges', 'deposit'] as $field) {
            if (null === $this->{$field}) {
                $context->buildViolation('listProperty.form.'.$field.'.notBlank')
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }

    public function isAirbnbOnly(): bool
    {
        return [] !== $this->leaseTypes
            && array_all($this->leaseTypes, static fn (LeaseType $type): bool => LeaseType::Airbnb === $type);
    }
}
