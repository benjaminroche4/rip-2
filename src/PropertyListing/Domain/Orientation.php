<?php

namespace App\PropertyListing\Domain;

enum Orientation: string
{
    case North = 'north';
    case South = 'south';
    case East = 'east';
    case West = 'west';
}
