<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Number of rooms wanted, from a studio to 5 and more (call-back form): "pièces" in French, shown as
 * bedrooms in English (2 pièces = 1 bedroom). Case order is the display order.
 */
enum Rooms: string
{
    case Studio = 'studio';
    case Two = 'two';
    case Three = 'three';
    case Four = 'four';
    case FivePlus = 'five_plus';

    public function labelKey(): string
    {
        return 'callback.form.rooms.choice.'.$this->value;
    }
}
