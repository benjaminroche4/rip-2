<?php

namespace App\Tests\Flow;

use App\Newsletter\Entity\Newsletter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards that the newsletter signup form actually surfaces validation errors
 * to the user. Previously the page appeared to "just reload" on invalid input
 * because no message was rendered close to the submit button.
 */
final class NewsletterErrorRenderTest extends WebTestCase
{
    private const FORM_PATH = '/fr/newsletter/rejoindre';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Newsletter::class)->execute();
    }

    public function testDuplicateEmailRendersErrorMessage(): void
    {
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

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('déjà inscrite', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testInvalidEmailFormatRendersErrorMessage(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[email]'] = 'not-an-email';

        $this->client->submit($form);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('pas valide', $html);
        self::assertCount(0, $this->em->getRepository(Newsletter::class)->findAll());
    }

    public function testEmptyEmailRendersErrorMessage(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[email]'] = '';

        $this->client->submit($form);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Veuillez renseigner votre adresse email', $html);
    }
}
