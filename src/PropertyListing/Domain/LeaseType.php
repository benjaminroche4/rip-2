<?php

namespace App\PropertyListing\Domain;

enum LeaseType: string
{
    case Alur = 'alur';
    case CivilCode = 'civil_code';
    case Mobility = 'mobility';
    case Airbnb = 'airbnb';
    case NoIdea = 'no_idea';
}
