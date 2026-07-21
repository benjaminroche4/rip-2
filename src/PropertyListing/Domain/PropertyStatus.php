<?php

namespace App\PropertyListing\Domain;

enum PropertyStatus: string
{
    case Available = 'available';
    case Rented = 'rented';
    case UnderRenovation = 'under_renovation';
}
