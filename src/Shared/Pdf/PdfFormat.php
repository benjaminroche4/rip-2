<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Paper format requested for a rendered PDF. Values are the canonical
 * names accepted by both DocRaptor (Prince) and the WHATWG print spec,
 * so the same enum can drive any future PDF backend without remapping.
 */
enum PdfFormat: string
{
    case A3 = 'A3';
    case A4 = 'A4';
    case A5 = 'A5';
    case Letter = 'Letter';
    case Legal = 'Legal';
}
