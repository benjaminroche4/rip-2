<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Thrown when a backend fails to turn HTML into a PDF: network error,
 * HTTP 4xx/5xx from the provider, quota exhausted, invalid input.
 * Always carries the underlying exception as `$previous` when available.
 */
class PdfRenderException extends \RuntimeException
{
}
