<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Language picked for both the PDF generation and the eventual send-out.
 * Mirrors the locales the site supports (fr/en).
 */
enum RequestLanguage: string
{
    case FR = 'fr';
    case EN = 'en';

    public function labelKey(): string
    {
        return 'admin.tools.documents.request.language.'.$this->value;
    }
}
