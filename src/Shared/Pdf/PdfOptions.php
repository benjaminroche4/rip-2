<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Backend-agnostic render options for a PDF. Anything that is specific
 * to a single provider (DocRaptor's `prince_options`, etc.) is mapped
 * by the implementation — callers describe *what* they want, not *how*
 * the provider must encode it.
 *
 * Margins follow the CSS `@page` convention: any unit Prince/Chrome
 * accepts (`12mm`, `0.5in`, `36pt`, …). `null` means "let the backend
 * pick a sensible default".
 */
final readonly class PdfOptions
{
    public function __construct(
        public PdfFormat $format = PdfFormat::A4,
        public PdfOrientation $orientation = PdfOrientation::Portrait,
        public ?string $marginTop = null,
        public ?string $marginRight = null,
        public ?string $marginBottom = null,
        public ?string $marginLeft = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function withMargins(string $top, string $right, string $bottom, string $left): self
    {
        return new self(
            format: $this->format,
            orientation: $this->orientation,
            marginTop: $top,
            marginRight: $right,
            marginBottom: $bottom,
            marginLeft: $left,
        );
    }
}
