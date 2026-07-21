<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PropertyListingControllerTest extends WebTestCase
{
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testPageLoadsInFrench(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/proposer-un-bien');

        self::assertResponseIsSuccessful();
    }

    public function testPageLoadsInEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/list-your-property');

        self::assertResponseIsSuccessful();
    }

    public function testItAcceptsSubmissionWithValidAddress(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[placeId]' => 'ChIJTestRivoli75001',
            'property_listing[note]' => 'Le bien est disponible à partir de septembre.',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/fr/proposer-un-bien', 303);
        self::assertEmailCount(2);

        // The collector reports each mail twice (queued + sent via the sync
        // Messenger transport), so pick messages by recipient instead of index.
        $byRecipient = [];
        foreach ($this->getMailerMessages() as $mail) {
            $byRecipient[$mail->getTo()[0]->getAddress()] = $mail;
        }
        self::assertArrayHasKey('contact@relocation-in-paris.fr', $byRecipient);
        self::assertArrayHasKey('marie.dupont@example.com', $byRecipient);
        self::assertEmailHtmlBodyContains($byRecipient['contact@relocation-in-paris.fr'], '12 rue de Rivoli, 75001 Paris');
        self::assertEmailHtmlBodyContains($byRecipient['contact@relocation-in-paris.fr'], 'Marie Dupont');
        self::assertEmailHtmlBodyContains($byRecipient['contact@relocation-in-paris.fr'], 'Le bien est disponible à partir de septembre.');
        self::assertEmailHtmlBodyContains($byRecipient['marie.dupont@example.com'], '12 rue de Rivoli, 75001 Paris');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // The confirmation view replaces the form: address card + the
        // "full summary sent by email" mention.
        self::assertSelectorExists('#listing-form [role="status"]');
        self::assertSelectorTextContains('#listing-form', '12 rue de Rivoli, 75001 Paris');
        self::assertSelectorTextContains('#listing-form', 'marie.dupont@example.com');
    }

    public function testItRejectsAFreeTypedAddressWithoutGoogleSelection(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        // Everything valid except placeId: the address was typed by hand, not
        // picked from the Google suggestions list.
        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
        self::assertEmailCount(0);
    }

    public function testItRejectsSubmissionWithBlankAddress(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '',
        ]);
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItRejectsSubmissionWithInvalidEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'not-an-email',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
        ]);
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItAcceptsAHouseWithoutFloor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'house',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[placeId]' => 'ChIJTestRivoli75001',
            // The floor block is hidden and disabled for a house.
            'property_listing[floor]' => '',
            'property_listing[buildingFloors]' => '',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/fr/proposer-un-bien', 303);
        self::assertEmailCount(2);
    }

    public function testItRejectsAnApartmentWithoutFloor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[placeId]' => 'ChIJTestRivoli75001',
            'property_listing[floor]' => '',
            'property_listing[buildingFloors]' => '',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItRejectsABuildingWithFewerFloorsThanThePropertyFloor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[floor]' => '5',
            'property_listing[buildingFloors]' => '3',
        ]);
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItAcceptsAnAirbnbOnlySubmissionWithoutFinancials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[placeId]' => 'ChIJTestRivoli75001',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        // "airbnb" only: rent, charges and deposit stay empty on purpose.
        $form['property_listing[leaseTypes]'][3]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/fr/proposer-un-bien', 303);
        self::assertEmailCount(2);
    }

    public function testItRejectsAClassicLeaseSubmissionWithoutRent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[placeId]' => 'ChIJTestRivoli75001',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItSilentlyAcceptsSubmissionWhenHoneypotIsFilledWithoutSendingAnything(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        $form = $crawler->filter('form[name="property_listing"]')->form([
            'property_listing[fullName]' => 'Marie Dupont',
            'property_listing[email]' => 'marie.dupont@example.com',
            'property_listing[phoneNumber]' => '+33612345678',
            'property_listing[propertyType]' => 'studio',
            'property_listing[propertyStatus]' => 'available',
            'property_listing[furnishing]' => 'furnished',
            'property_listing[rent]' => '1500',
            'property_listing[charges]' => '150',
            'property_listing[deposit]' => '1500',
            'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
            'property_listing[website]' => 'https://spam.example',
        ]);
        $form['property_listing[orientations]'][1]->tick();
        $form['property_listing[leaseTypes]'][0]->tick();
        $client->submit($form);

        // The bot sees the exact same response as a legitimate success, but
        // no email leaves the application.
        self::assertResponseRedirects('/fr/proposer-un-bien', 303);
        self::assertEmailCount(0);
    }

    public function testItReturns429WhenRateLimitIsExceeded(): void
    {
        $client = static::createClient();
        // Keep the same kernel across requests so the limiter state consumed
        // below is still visible when the POST is handled.
        $client->disableReboot();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');

        // Drain the whole test-env allowance instead of firing 1000 requests.
        // The window state persists on the filesystem between runs: reset it
        // first so leftover hits from a previous run cannot make the drain
        // itself get rejected.
        $limiter = static::getContainer()->get('limiter.form_listing')->create('127.0.0.1');
        $limiter->reset();
        self::assertTrue($limiter->consume(1000)->isAccepted(), 'The drain itself should be accepted.');

        try {
            $form = $crawler->filter('form[name="property_listing"]')->form([
                'property_listing[fullName]' => 'Marie Dupont',
                'property_listing[email]' => 'marie.dupont@example.com',
                'property_listing[phoneNumber]' => '+33612345678',
                'property_listing[propertyType]' => 'studio',
                'property_listing[propertyStatus]' => 'available',
                'property_listing[furnishing]' => 'furnished',
                'property_listing[rent]' => '1500',
                'property_listing[charges]' => '150',
                'property_listing[deposit]' => '1500',
                'property_listing[address]' => '12 rue de Rivoli, 75001 Paris',
                'property_listing[placeId]' => 'ChIJTestRivoli75001',
            ]);
            $form['property_listing[orientations]'][1]->tick();
            $form['property_listing[leaseTypes]'][0]->tick();
            $client->submit($form);

            self::assertResponseStatusCodeSame(429);
            self::assertSelectorExists('#listing-form [role="alert"]');
            self::assertEmailCount(0);
        } finally {
            // The limiter state lives in cache.app: reset it so later tests
            // in the same run are not throttled.
            $limiter->reset();
        }
    }

    public function testItStoresUploadedPhotosInASlugNamedFolder(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');
        $token = $crawler->filter('input[name="property_listing[_token]"]')->attr('value');

        $tmpPhoto = tempnam(sys_get_temp_dir(), 'listing').'.png';
        file_put_contents($tmpPhoto, base64_decode(self::TINY_PNG_BASE64));

        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        // The folder name carries a -YYYYMMDD suffix: match it by prefix so
        // the test does not depend on the clock.
        $pattern = $projectDir.'/var/uploads/properties/submissions/12ruederivoli75001parismariedupont-*';
        $filesystem = new Filesystem();
        $filesystem->remove(glob($pattern) ?: []);

        $client->request('POST', '/fr/proposer-un-bien', [
            'property_listing' => [
                'fullName' => 'Marie Dupont',
                'email' => 'marie.dupont@example.com',
                'phoneNumber' => '+33612345678',
                'propertyType' => 'studio',
                'propertyStatus' => 'available',
                'furnishing' => 'furnished',
                'leaseTypes' => ['alur'],
                'rent' => 1500,
                'charges' => 150,
                'deposit' => 1500,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'surface' => 45,
                'floor' => 3,
                'buildingFloors' => 6,
                'orientations' => ['south'],
                'address' => '12 Rue de Rivoli, 75001 Paris',
                'placeId' => 'ChIJTestRivoli75001',
                '_token' => $token,
            ],
        ], [
            'property_listing' => [
                'photos' => [new UploadedFile($tmpPhoto, 'salon.png', 'image/png', null, true)],
            ],
        ]);

        self::assertResponseRedirects('/fr/proposer-un-bien', 303);
        $directories = glob($pattern) ?: [];
        self::assertCount(1, $directories);
        self::assertCount(1, glob($directories[0].'/*.png') ?: []);

        // The photos travel as attachments on the admin email only; the
        // owner's confirmation just mentions the count.
        $byRecipient = [];
        foreach ($this->getMailerMessages() as $mail) {
            $byRecipient[$mail->getTo()[0]->getAddress()] = $mail;
        }
        self::assertCount(1, $byRecipient['contact@relocation-in-paris.fr']->getAttachments());
        self::assertCount(0, $byRecipient['marie.dupont@example.com']->getAttachments());
        self::assertEmailHtmlBodyContains($byRecipient['marie.dupont@example.com'], '1 photo transmise avec votre demande');

        $filesystem->remove($directories);
    }

    public function testItRejectsAPhotoThatIsNotAnImage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/proposer-un-bien');
        $token = $crawler->filter('input[name="property_listing[_token]"]')->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'listing').'.txt';
        file_put_contents($tmpFile, 'definitely not an image');

        $client->request('POST', '/fr/proposer-un-bien', [
            'property_listing' => [
                'fullName' => 'Marie Dupont',
                'email' => 'marie.dupont@example.com',
                'phoneNumber' => '+33612345678',
                'propertyType' => 'studio',
                'propertyStatus' => 'available',
                'furnishing' => 'furnished',
                'leaseTypes' => ['alur'],
                'rent' => 1500,
                'charges' => 150,
                'deposit' => 1500,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'surface' => 45,
                'floor' => 3,
                'buildingFloors' => 6,
                'orientations' => ['south'],
                'address' => '12 Rue de Rivoli, 75001 Paris',
                'placeId' => 'ChIJTestRivoli75001',
                '_token' => $token,
            ],
        ], [
            'property_listing' => [
                'photos' => [new UploadedFile($tmpFile, 'notes.txt', 'text/plain', null, true)],
            ],
        ]);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('#listing-form [role="alert"]');
    }

    public function testItRejectsSubmissionWithInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/proposer-un-bien');

        $client->request('POST', '/fr/proposer-un-bien', [
            'property_listing' => [
                'address' => '12 rue de Rivoli, 75001 Paris',
                '_token' => 'invalid-token',
            ],
        ]);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422]);
    }
}
