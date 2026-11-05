<?php

namespace App\CaseStudy\Domain;

/**
 * Package the client chose, same keys as the pricing page and the contact form offers.
 */
enum CaseStudyOffer: string
{
    case Accompagne = 'accompagne';
    case Confie = 'confie';

    public function labelKey(): string
    {
        return 'contact.contactForm.offer.'.$this->value.'.title';
    }
}
