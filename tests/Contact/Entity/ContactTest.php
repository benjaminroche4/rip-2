<?php

declare(strict_types=1);

namespace App\Tests\Contact\Entity;

use App\Contact\Entity\Contact;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    public function testItGeneratesAPrefixedReferenceOnCreation(): void
    {
        $contact = new Contact();

        self::assertMatchesRegularExpression('/^CT-\d{6}$/', $contact->getReference());
    }

    public function testReferenceCanBeOverridden(): void
    {
        $contact = (new Contact())->setReference('CT-082820');

        self::assertSame('CT-082820', $contact->getReference());
    }
}
