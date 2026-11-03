<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Whether the visitor has a rental guarantor, and where (call-back form).
 */
enum Guarantor: string
{
    case France = 'france';
    case Abroad = 'abroad';
    case None = 'none';

    public function labelKey(): string
    {
        return 'callback.form.guarantor.choice.'.$this->value;
    }
}
