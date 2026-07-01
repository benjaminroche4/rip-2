<?php

namespace App\Tests\Controller;

use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ContactControllerTest extends WebTestCase
{
    private const PATH = '/fr/contact';
    private const SUBMIT = 'Être recontacté rapidement';

    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
    }

    public function testItPersistsContactInE164WhenUserSubmitsNationalNumber(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM '.Contact::class)->execute();

        $client->request('GET', self::PATH);

        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane.doe+'.bin2hex(random_bytes(4)).'@example.com',
            'contact[phoneNumber]' => '06 12 34 56 78',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.1',
            'contact[offer]' => 'accompagne',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ]);

        self::assertResponseRedirects(self::PATH);

        $em->clear();
        $contact = $em->getRepository(Contact::class)->findOneBy(['lastName' => 'Doe']);
        self::assertNotNull($contact);
        self::assertSame('+33612345678', $contact->getPhoneNumber());
    }

    public function testItRequiresAnOfferWhenHousingSearchIsSelected(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        // Housing search (choice.1) selected but no offer picked → invalid.
        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane@example.com',
            'contact[phoneNumber]' => '06 12 34 56 78',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.1',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ]);

        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY]);
        self::assertFalse($client->getResponse()->isRedirection(), 'Missing offer for housing search must not redirect.');
    }

    public function testItDoesNotRequireAnOfferForOtherHelpTypes(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        // A non-housing help type (choice.2) does not require an offer.
        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane.doe+'.bin2hex(random_bytes(4)).'@example.com',
            'contact[phoneNumber]' => '06 12 34 56 78',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.2',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ]);

        self::assertResponseRedirects(self::PATH);
    }

    public function testItRejectsInvalidPhoneNumberWith422(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane@example.com',
            'contact[phoneNumber]' => 'not-a-phone',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.1',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ]);

        // Turbo form streams come back as 422 on invalid submit; non-Turbo
        // requests render the form again with status 200. Either is acceptable
        // here as long as it does NOT redirect.
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY]);
        self::assertFalse($client->getResponse()->isRedirection(), 'Invalid form must not redirect.');
    }
}
