<?php

namespace App\Tests\Flow;

use App\Contact\Entity\Contact;
use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Webhook\NotifyMakeWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Integration test on the contact lead flow.
 *
 * The path being protected: someone hits /fr/contact, fills the form, the
 * controller persists a Contact row and dispatches two async messages
 * (email + Make webhook). If anyone breaks the dispatch wiring, the form
 * happily returns 302 → the visitor sees "merci on vous recontacte" → the
 * lead silently never reaches the team. This test catches that.
 */
final class ContactFlowTest extends WebTestCase
{
    private const CONTACT_PATH = '/fr/contact';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM ' . Contact::class)->execute();
    }

    public function testValidSubmissionPersistsContactAndDispatchesBothMessages(): void
    {
        $crawler = $this->client->request('GET', self::CONTACT_PATH);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="contact"]')->form();
        $form['contact[firstName]'] = 'John';
        $form['contact[lastName]'] = 'Doe';
        $form['contact[email]'] = 'john.doe@example.com';
        $form['contact[phoneNumber]'] = '+33612345678';
        $form['contact[helpType]'] = 'contact.contactForm.helpType.choice.1';
        $form['contact[message]'] = 'I would like a 2-bedroom apartment.';
        $form['contact[company]'] = 'Acme Inc.';
        $form['contact[accept]']->tick();

        $this->client->submit($form);

        // 1. Controller redirected (the success path — Turbo stream is only
        //    emitted when the request advertises that format).
        self::assertResponseRedirects(self::CONTACT_PATH);

        // 2. Contact row persisted with the form payload + server-side fields
        //    (locale, ip, createdAt) attached by the controller.
        $rows = $this->em->getRepository(Contact::class)->findAll();
        self::assertCount(1, $rows);
        $contact = $rows[0];
        self::assertSame('John', $contact->getFirstName());
        self::assertSame('john.doe@example.com', $contact->getEmail());
        self::assertSame('Acme Inc.', $contact->getCompany());
        self::assertSame('fr', $contact->getLang());
        self::assertNotNull($contact->getCreatedAt());

        // 3. Two messages on the async transport (in-memory in tests):
        //    SendContactEmailsMessage (admin + client emails)
        //    NotifyMakeWebhookMessage (Make.com webhook payload)
        $envelopes = $this->asyncTransport()->getSent();
        $messages = array_map(fn ($e) => $e->getMessage(), $envelopes);

        $emailMessages = array_filter($messages, fn ($m) => $m instanceof SendContactEmailsMessage);
        $webhookMessages = array_filter($messages, fn ($m) => $m instanceof NotifyMakeWebhookMessage);

        self::assertCount(1, $emailMessages, 'Expected exactly one SendContactEmailsMessage on the async bus.');
        self::assertCount(1, $webhookMessages, 'Expected exactly one NotifyMakeWebhookMessage on the async bus.');

        /** @var SendContactEmailsMessage $emailMsg */
        $emailMsg = array_values($emailMessages)[0];
        self::assertSame('John', $emailMsg->firstName);
        self::assertSame('john.doe@example.com', $emailMsg->email);
        self::assertSame('fr', $emailMsg->lang);

        /** @var NotifyMakeWebhookMessage $webhookMsg */
        $webhookMsg = array_values($webhookMessages)[0];
        self::assertSame('john.doe@example.com', $webhookMsg->payload['email']);
        self::assertSame('Acme Inc.', $webhookMsg->payload['company']);
    }

    public function testInvalidSubmissionReturns200WithoutDispatch(): void
    {
        $crawler = $this->client->request('GET', self::CONTACT_PATH);
        $form = $crawler->filter('form[name="contact"]')->form();
        // Skip required fields entirely → form fails validation.
        $form['contact[firstName]'] = '';
        $form['contact[email]'] = '';

        $this->client->submit($form);

        // Symfony returns 200 (legacy form re-render) or 422 (Turbo-aware
        // unprocessable-content). Either way: no persist, no dispatch.
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 422], "Unexpected status {$status}");
        self::assertCount(0, $this->em->getRepository(Contact::class)->findAll());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        return $transport;
    }
}
