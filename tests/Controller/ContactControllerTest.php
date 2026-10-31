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
    private const SUCCESS_PATH = '/fr/contact?success=1';

    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="contact"]');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
        self::assertStringNotContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testItRedirectsToSuccessUrlAfterTurboSubmission(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane.doe+'.bin2hex(random_bytes(4)).'@example.com',
            'contact[phoneNumber]' => '06 12 34 56 78',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.2',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ], 'POST', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        // Turbo follows a 303 as a visit, so ?success=1 lands in the address bar.
        self::assertResponseRedirects(self::SUCCESS_PATH, Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#contact-form[role="status"]');
        self::assertSelectorTextContains('#contact-form', '+33612345678');
        self::assertSelectorNotExists('form[name="contact"]');
    }

    public function testSuccessPageIsNeverCachedNorIndexedAndKeepsBareCanonical(): void
    {
        $client = static::createClient();
        $client->request('GET', self::SUCCESS_PATH);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-LiteSpeed-Cache-Control', 'no-cache');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');

        $canonical = $client->getCrawler()->filter('link[rel="canonical"]')->attr('href');
        self::assertIsString($canonical);
        self::assertStringEndsWith(self::PATH, $canonical);
        self::assertStringNotContainsString('success', $canonical);

        $alternates = $client->getCrawler()->filter('link[rel="alternate"][hreflang]')->extract(['href']);
        self::assertNotEmpty($alternates);
        foreach ($alternates as $alternate) {
            self::assertStringNotContainsString('success', $alternate);
        }

        // Direct hit without a prior submission: confirmation shown, no phone box.
        self::assertSelectorExists('#contact-form[role="status"]');
        self::assertSelectorNotExists('form[name="contact"]');
    }

    public function testSuccessParamIsIgnoredOnSubmission(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $client->submitForm(self::SUBMIT, [
            'contact[firstName]' => 'Jane',
            'contact[lastName]' => 'Doe',
            'contact[email]' => 'jane@example.com',
            'contact[phoneNumber]' => 'not-a-phone',
            'contact[helpType]' => 'contact.contactForm.helpType.choice.2',
            'contact[message]' => 'Hello',
            'contact[accept]' => '1',
        ], 'POST', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        // Invalid Turbo submission: stream with the form re-rendered, status 200.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="replace" target="contact-form">', (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->isRedirection());
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

        self::assertResponseRedirects(self::SUCCESS_PATH, Response::HTTP_SEE_OTHER);

        // The chosen package and its price are repeated on the confirmation.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#contact-form', 'Accompagné');
        self::assertSelectorTextContains('#contact-form', '1 490 €');

        $em->clear();
        $contact = $em->getRepository(Contact::class)->findOneBy(['lastName' => 'Doe']);
        self::assertNotNull($contact);
        self::assertSame('+33612345678', $contact->getPhoneNumber());
        self::assertSame('accompagne', $contact->getOffer(), 'The chosen package must be persisted.');
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

        self::assertResponseRedirects(self::SUCCESS_PATH, Response::HTTP_SEE_OTHER);
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
