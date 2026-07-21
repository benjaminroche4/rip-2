<?php

namespace App\PropertyListing\Domain;

enum Furnishing: string
{
    case Furnished = 'furnished';
    case Unfurnished = 'unfurnished';
}
