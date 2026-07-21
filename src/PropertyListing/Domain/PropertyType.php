<?php

namespace App\PropertyListing\Domain;

enum PropertyType: string
{
    case Studio = 'studio';
    case T1 = 't1';
    case T2 = 't2';
    case T3 = 't3';
    case T4 = 't4';
    case LargeApartment = 'large_apartment';
    case Duplex = 'duplex';
    case Loft = 'loft';
    case House = 'house';
}
