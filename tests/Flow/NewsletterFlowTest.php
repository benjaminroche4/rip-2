<?php

namespace App\Tests\Flow;

use App\Newsletter\Entity\Newsletter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration test on the newsletter signup flow.
 *
 * Lighter than Contact/Estimation: there's no async dispatch on this path
 * yet (the Resend integration was commented out earlier). The unit being
 * protected is "form binds → entity persists with subscribe=true". If anyone
 * accidentally drops the persist, signups silently disappear.
 */
final class NewsletterFlowTest extends WebTestCase
{
    private const FORM_PATH = '/fr/newsletter/rejoindre';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM ' . Newsletter::class)->execute();
    }

    public function testValidEmailPersistsSubscriber(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[email]'] = 'subscriber@example.com';

        $this->client->submit($form);

        self::assertResponseRedirects(self::FORM_PATH);

        $rows = $this->em->getRepository(Newsletter::class)->findAll();
        self::assertCount(1, $rows);
        $sub = $rows[0];
        self::assertSame('subscriber@example.com', $sub->getEmail());
        self::assertTrue($sub->isSubscribe(), 'Newsletter subscribe flag must default to true.');
        self::assertNotNull($sub->getCreatedAt());
    }

    public function testEmptyEmailReturnsFormWithoutPersist(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[email]'] = '';

        $this->client->submit($form);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 422], "Unexpected status {$status}");
        self::assertCount(0, $this->em->getRepository(Newsletter::class)->findAll());
    }

    public function testDuplicateEmailDoesNotCreateSecondRow(): void
    {
        // Seed an existing subscriber.
        $existing = (new Newsletter())
            ->setEmail('already@example.com')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSubscribe(true);
        $this->em->persist($existing);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::FORM_PATH);
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[email]'] = 'already@example.com';

        $this->client->submit($form);

        // The Newsletter entity carries a UniqueEntity constraint on email.
        // Symfony rejects the form → no second row appears.
        self::assertCount(1, $this->em->getRepository(Newsletter::class)->findAll());
    }
}
