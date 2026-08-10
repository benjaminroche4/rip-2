<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // The whole business runs on Paris time (admin inputs, emails,
        // calendar invites). Never trust the host's php.ini for this:
        // o2switch and most dev setups default to UTC, which silently
        // shifted every planned slot by one or two hours.
        date_default_timezone_set('Europe/Paris');

        parent::boot();
    }
}
