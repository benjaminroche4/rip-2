<?php

namespace App\Tests\Flow;

use App\PropertyEstimation\Entity\PropertyEstimation;
use App\PropertyEstimation\Message\SendEstimationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Integration test on the property-estimation lead flow.
 *
 * Same pattern as ContactFlowTest. Hits /fr/services/gestion-locative-paris,
 * fills the form, asserts the controller persists a PropertyEstimation row
 * and dispatches a SendEstimationEmailMessage on the async bus.
 */
final class PropertyEstimationFlowTest extends WebTestCase
{
    private const FORM_PATH = '/fr/services/gestion-locative-paris';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM ' . PropertyEstimation::class)->execute();
    }

    public function testValidSubmissionPersistsAndDispatchesEmail(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="property_estimation"]')->form();
        $form['property_estimation[address]'] = '12 rue de Test, 75011 Paris';
        $form['property_estimation[propertyCondition]'] = 'propertyManagement.form.propertyCondition.choice.1';
        $form['property_estimation[bedroom]'] = '2';
        $form['property_estimation[bathroom]'] = '1';
        $form['property_estimation[surface]'] = '45';
        $form['property_estimation[phoneNumber]'] = '+33612345678';
        $form['property_estimation[email]'] = 'landlord@example.com';

        $this->client->submit($form);

        // Controller redirects (303 See Other on this flow).
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        // Entity persisted with form data + locale/createdAt added by the controller.
        $rows = $this->em->getRepository(PropertyEstimation::class)->findAll();
        self::assertCount(1, $rows);
        $estimation = $rows[0];
        self::assertSame('12 rue de Test, 75011 Paris', $estimation->getAddress());
        self::assertSame(45, $estimation->getSurface());
        self::assertSame(2, $estimation->getBedroom());
        self::assertSame('landlord@example.com', $estimation->getEmail());
        self::assertSame('fr', $estimation->getLang());
        self::assertNotNull($estimation->getCreatedAt());

        // Exactly one async email message dispatched, carrying the form fields.
        $envelopes = $this->asyncTransport()->getSent();
        $messages = array_filter(
            array_map(fn ($e) => $e->getMessage(), $envelopes),
            fn ($m) => $m instanceof SendEstimationEmailMessage,
        );
        self::assertCount(1, $messages);

        /** @var SendEstimationEmailMessage $msg */
        $msg = array_values($messages)[0];
        self::assertSame('landlord@example.com', $msg->email);
        self::assertSame(45, $msg->surface);
        self::assertSame('fr', $msg->lang);
    }

    public function testInvalidSubmissionReturnsFormWithoutDispatch(): void
    {
        $crawler = $this->client->request('GET', self::FORM_PATH);
        $form = $crawler->filter('form[name="property_estimation"]')->form();
        $form['property_estimation[address]'] = '';
        $form['property_estimation[email]'] = '';

        $this->client->submit($form);

        // Symfony returns 200 (legacy form re-render) or 422 (Turbo-aware
        // unprocessable-content). Either way means "no dispatch happened".
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 422], "Unexpected status {$status}");
        self::assertCount(0, $this->em->getRepository(PropertyEstimation::class)->findAll());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        return $transport;
    }
}
