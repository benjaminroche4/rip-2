<?php

namespace App\Tests\Shared\Webhook;

use App\Contact\Entity\Contact;
use App\Shared\Webhook\DashboardContactPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardContactPayloadTest extends TestCase
{
    public function testItSendsRawValuesWithAStableHelpTypeSlug(): void
    {
        $contact = (new Contact())
            ->setFirstName('John')
            ->setLastName('Doe')
            ->setEmail('john.doe@example.com')
            ->setPhoneNumber('+33612345678')
            ->setCompany('Acme Inc.')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setOffer('accompagne')
            ->setMessage('Hello')
            ->setLang('en')
            ->setCreatedAt(new \DateTimeImmutable('2026-09-06T10:00:00+02:00'));

        $payload = DashboardContactPayload::fromContact($contact);

        self::assertSame($contact->getReference(), $payload['reference']);
        self::assertStringStartsWith('CT-', $payload['reference']);
        self::assertSame('housing_search', $payload['help_type']);
        self::assertSame('accompagne', $payload['offer']);
        self::assertSame('en', $payload['lang']);
        self::assertSame('2026-09-06T10:00:00+02:00', $payload['created_at']);
        self::assertSame(
            ['reference', 'first_name', 'last_name', 'email', 'phone', 'company', 'help_type', 'offer', 'message', 'lang', 'created_at'],
            array_keys($payload),
        );
    }

    #[DataProvider('helpTypes')]
    public function testEveryHelpTypeKeyMapsToASlug(?string $key, string $slug): void
    {
        self::assertSame($slug, DashboardContactPayload::helpTypeSlug($key));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function helpTypes(): iterable
    {
        yield 'housing' => ['contact.contactForm.helpType.choice.1', 'housing_search'];
        yield 'business' => ['contact.contactForm.helpType.choice.2', 'business'];
        yield 'rental' => ['contact.contactForm.helpType.choice.3', 'rental_management'];
        yield 'other' => ['contact.contactForm.helpType.choice.4', 'other'];
        yield 'unknown falls back to other' => ['something-else', 'other'];
        yield 'null falls back to other' => [null, 'other'];
    }
}
