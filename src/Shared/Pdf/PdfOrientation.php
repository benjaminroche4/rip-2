<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

enum PdfOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';
}
