<?php

declare(strict_types=1);

namespace App\Tests\Contact\MessageHandler;

use App\Contact\Message\SendContactEmailsMessage;
use App\Contact\MessageHandler\SendContactEmailsHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class SendContactEmailsHandlerTest extends KernelTestCase
{
    public function testCallBackRequestsGetTheirOwnEmailsWithTheAppointment(): void
    {
        self::bootKernel();
        $handler = static::getContainer()->get(SendContactEmailsHandler::class);

        $handler(self::message(callbackAnswers: [
            'situation' => 'family',
            'settleTiming' => 'asap',
            'furnishing' => 'unfurnished',
            'rooms' => 'three',
            'rentBudget' => 'up_to_2500',
            'guarantor' => 'abroad',
            'contactMethod' => 'whatsapp',
            'contactSlot' => 'morning',
        ]));

        self::assertEmailCount(2);
        [$admin, $client] = self::getMailerMessages();
        self::assertInstanceOf(TemplatedEmail::class, $admin);
        self::assertSame('📞 Demande de rappel | Relocation In Paris', $admin->getSubject());
        foreach (['Nouvelle demande de rappel', 'Rendez-vous', 'Le matin (8h - 12h)', 'Appel WhatsApp au', 'Formule choisie', 'Famille', 'Oui, à l&#039;étranger', "Jusqu&#039;à 2\u{00A0}500\u{00A0}€"] as $text) {
            self::assertEmailHtmlBodyContains($admin, $text);
        }

        self::assertInstanceOf(TemplatedEmail::class, $client);
        self::assertSame('Jane, votre demande de rappel est confirmée', $client->getSubject());
        foreach (['Votre demande de rappel est confirmée', 'Il vous appellera', 'Le matin (8h - 12h)', 'Nous vous appelons sur WhatsApp au', 'Votre projet', "3\u{00A0}pièces"] as $text) {
            self::assertEmailHtmlBodyContains($client, $text);
        }
        self::assertEmailHtmlBodyNotContains($client, '30 minutes maximum');
    }

    public function testAsSoonAsPossibleKeepsTheThirtyMinutesPromise(): void
    {
        self::bootKernel();
        static::getContainer()->get(SendContactEmailsHandler::class)(self::message(callbackAnswers: ['contactSlot' => 'asap', 'contactMethod' => 'phone']));

        [$admin, $client] = self::getMailerMessages();
        self::assertEmailHtmlBodyContains($admin, 'Dès que possible');
        self::assertEmailHtmlBodyContains($client, '30 minutes maximum');
        self::assertInstanceOf(TemplatedEmail::class, $client);
        self::assertSame('Jane, votre conseiller vous contacte sous 30 minutes', $client->getSubject());
    }

    public function testTheContactFormKeepsItsEmails(): void
    {
        self::bootKernel();
        static::getContainer()->get(SendContactEmailsHandler::class)(self::message());

        [$admin, $client] = self::getMailerMessages();
        self::assertInstanceOf(TemplatedEmail::class, $admin);
        self::assertSame('📩 Demande de contact | Relocation In Paris', $admin->getSubject());
        self::assertEmailHtmlBodyContains($admin, 'Nouvelle demande de contact');
        self::assertEmailHtmlBodyNotContains($admin, 'Rendez-vous');
        self::assertEmailHtmlBodyContains($client, 'Merci de nous avoir contactés');
    }

    /** @param array<string, string>|null $callbackAnswers */
    private static function message(?array $callbackAnswers = null): SendContactEmailsMessage
    {
        return new SendContactEmailsMessage(
            firstName: 'Jane',
            lastName: 'Doe',
            email: 'jane.doe@example.com',
            phoneNumber: '+33612345678',
            helpType: 'contact.contactForm.helpType.choice.1',
            message: null === $callbackAnswers ? 'Hello' : 'Demande de rappel',
            company: null,
            lang: 'fr',
            ip: '127.0.0.1',
            createdAt: new \DateTimeImmutable('2026-11-03 15:40'),
            offer: 'confie',
            callbackAnswers: $callbackAnswers,
        );
    }
}
