<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Color tone of the move-in progress bar: the closer the deadline, the
 * warmer the color.
 */
enum ProgressTone: string
{
    case OnTrack = 'green';
    case Approaching = 'amber';
    case Critical = 'red';
}
