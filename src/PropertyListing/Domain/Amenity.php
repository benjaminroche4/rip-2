<?php

namespace App\PropertyListing\Domain;

/**
 * Case order matters: the form shows the first ten as-is and folds the rest
 * behind a "show more" toggle, so the most common amenities come first.
 */
enum Amenity: string
{
    case Elevator = 'elevator';
    case Balcony = 'balcony';
    case Terrace = 'terrace';
    case Wifi = 'wifi';
    case WashingMachine = 'washing_machine';
    case Dishwasher = 'dishwasher';
    case Oven = 'oven';
    case Tv = 'tv';
    case AirConditioning = 'air_conditioning';
    case Parking = 'parking';
    case Cellar = 'cellar';
    case Garden = 'garden';
    case Dryer = 'dryer';
    case Microwave = 'microwave';
    case Bathtub = 'bathtub';
    case Intercom = 'intercom';
    case Concierge = 'concierge';
    case NaturalLight = 'natural_light';
    case DoubleGlazing = 'double_glazing';
    case WheelchairAccess = 'wheelchair_access';
    case BikeStorage = 'bike_storage';
    case Workspace = 'workspace';
    case Gym = 'gym';
    case Pool = 'pool';
}
