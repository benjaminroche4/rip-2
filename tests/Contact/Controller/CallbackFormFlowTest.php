<?php

declare(strict_types=1);

namespace App\Tests\Contact\Controller;

use App\Contact\Domain\ContactMethod;
use App\Contact\Domain\ContactSlot;
use App\Contact\Domain\Furnishing;
use App\Contact\Domain\Guarantor;
use App\Contact\Domain\RentBudget;
use App\Contact\Domain\Rooms;
use App\Contact\Domain\SettleTiming;
use App\Contact\Domain\Situation;
use App\Contact\Entity\Contact;
use App\Contact\Form\ContactType;
use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Webhook\NotifyDashboardMessage;
use App\Shared\Webhook\NotifyMakeWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * "On vous rappelle" call-back form: a revenue path, covered like the contact
 * form (success, validation, honeypot, rate limit, confirmation caching).
 */
final class CallbackFormFlowTest extends WebTestCase
{
    private const PATH = '/fr/on-vous-rappelle';
    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
    }

    /** @return array<string, string> */
    private static function validValues(): array
    {
        return [
            'callback[firstName]' => 'Jane',
            'callback[lastName]' => 'Doe',
            'callback[situation]' => 'family',
            'callback[offer]' => 'confie',
            'callback[settleTiming]' => 'one_to_two_months',
            'callback[furnishing]' => 'unfurnished',
            'callback[rooms]' => 'three',
            'callback[guarantor]' => 'abroad',
            'callback[rentBudget]' => 'up_to_4000',
            'callback[contactMethod]' => 'whatsapp',
            'callback[email]' => 'jane.doe@example.com',
            'callback[phoneNumber]' => '06 12 34 56 78',
            'callback[contactSlot]' => 'afternoon',
            'callback[accept]' => '1',
        ];
    }

    /** @param array<string, string> $values */
    private function submit(array $values, bool $turbo = true): void
    {
        $crawler = $this->client->request('GET', self::PATH);
        $form = $crawler->filter('form[name="callback"]')->form();
        $form->disableValidation();
        $form->setValues($values);
        $this->client->submit($form, [], $turbo ? self::TURBO : []);
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    public function testItRendersTheCallbackFormWithCardChoices(): void
    {
        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Réservez un appel avec un expert');
        // Situation (6), settle timing (4), offer (2), furnishing (3), rooms (5), rent budget (4),
        // guarantor (3), contact method (2), contact slot (4): all one-tap cards
        self::assertCount(33, $crawler->filter('form[name="callback"] input[type="radio"]'));
        self::assertSelectorNotExists('form[name="callback"] select');
        // Life stages in order, "other" last
        self::assertSame(
            ['student', 'professional', 'entrepreneur', 'expat', 'family', 'other'],
            $crawler->filter('input[name="callback[situation]"]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        self::assertSelectorExists('input[name="callback[_token]"]');
        // From a studio up to 5 rooms and more
        self::assertSame(
            ['studio', 'two', 'three', 'four', 'five_plus'],
            $crawler->filter('input[name="callback[rooms]"]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        // Rent searches start at 1 000 €
        self::assertSame(
            ['up_to_1500', 'up_to_2500', 'up_to_4000', 'over_4000'],
            $crawler->filter('input[name="callback[rentBudget]"]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        // Reassurance (CSS only) under the guarantor question; answers without a French guarantor hold the auto-advance
        self::assertSelectorExists('fieldset[data-name="callback[guarantor]"] [data-testid="guarantor-reassurance"].hidden');
        // "We do it for you", not "we point you to"
        self::assertSelectorTextContains('[data-testid="guarantor-reassurance"]', 'nous prenons tout en main pour vous obtenir une solution de garantie');
        self::assertSame(
            ['abroad', 'none'],
            $crawler->filter('input[name="callback[guarantor]"][data-form-steps-hold]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        // Always a call: WhatsApp first, then a regular phone call (no video)
        self::assertSame(
            ['whatsapp', 'phone'],
            $crawler->filter('input[name="callback[contactMethod]"]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        // Same packages and prices as the pricing page
        self::assertSelectorTextContains('form[name="callback"]', '1 490 €');
        self::assertSelectorTextContains('form[name="callback"]', '2 190 €');
    }

    public function testValidSubmissionPersistsTheAnswersAndNotifiesTheTeam(): void
    {
        $this->submit(self::validValues());

        self::assertResponseRedirects(self::PATH.'?success=1', Response::HTTP_SEE_OTHER);

        $rows = $this->em->getRepository(Contact::class)->findAll();
        self::assertCount(1, $rows);
        $contact = $rows[0];
        self::assertSame(Contact::SOURCE_CALLBACK, $contact->getSource());
        self::assertSame(ContactType::HOUSING_HELP_TYPE, $contact->getHelpType());
        self::assertSame('confie', $contact->getOffer());
        self::assertSame(Situation::Family, $contact->getSituation());
        self::assertSame(SettleTiming::OneToTwoMonths, $contact->getSettleTiming());
        self::assertSame(Furnishing::Unfurnished, $contact->getFurnishing());
        self::assertSame(Rooms::Three, $contact->getRooms());
        self::assertSame(Guarantor::Abroad, $contact->getGuarantor());
        self::assertSame(RentBudget::UpTo4000, $contact->getRentBudget());
        self::assertSame(ContactMethod::WhatsApp, $contact->getContactMethod());
        self::assertSame(ContactSlot::Afternoon, $contact->getContactSlot());
        self::assertSame('+33612345678', $contact->getPhoneNumber());
        self::assertSame('fr', $contact->getLang());

        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $this->asyncTransport()->getSent());

        $emails = array_values(array_filter($messages, static fn ($m) => $m instanceof SendContactEmailsMessage));
        self::assertCount(1, $emails);
        self::assertStringContainsString('Demande de rappel', (string) $emails[0]->message);
        self::assertStringContainsString('Garant : Oui, à l\'étranger', (string) $emails[0]->message);
        self::assertStringContainsString("Loyer maximum : Jusqu'à 4\u{00A0}000\u{00A0}€", (string) $emails[0]->message);
        self::assertStringContainsString('Situation : Famille', (string) $emails[0]->message);
        self::assertSame('confie', $emails[0]->offer);

        $make = array_values(array_filter($messages, static fn ($m) => $m instanceof NotifyMakeWebhookMessage));
        self::assertCount(1, $make);
        $payload = $make[0]->payload;
        self::assertSame('callback', $payload['source']);
        self::assertSame('Non meublé', $payload['furnishing']);
        self::assertSame("3\u{00A0}pièces", $payload['rooms']);
        self::assertSame("Oui, à l'étranger", $payload['guarantor']);
        self::assertSame("Jusqu'à 4\u{00A0}000\u{00A0}€", $payload['rentBudget']);
        self::assertSame('Famille', $payload['situation']);
        self::assertSame('Appel WhatsApp', $payload['contactMethod']);
        self::assertArrayNotHasKey('stayType', $payload);

        $dashboard = array_values(array_filter($messages, static fn ($m) => $m instanceof NotifyDashboardMessage));
        self::assertCount(1, $dashboard);
        self::assertStringContainsString("Créneau : L'après-midi (12h - 17h)", (string) $dashboard[0]->payload['message']);
    }

    public function testOptionalCardChoicesCanBeLeftEmpty(): void
    {
        $values = self::validValues();
        unset($values['callback[contactMethod]'], $values['callback[contactSlot]']);

        $this->submit($values);

        self::assertResponseRedirects(self::PATH.'?success=1', Response::HTTP_SEE_OTHER);
        $contact = $this->em->getRepository(Contact::class)->findAll()[0];
        self::assertNull($contact->getContactSlot());
    }

    public function testRoomsReadAsBedroomsInEnglish(): void
    {
        $crawler = $this->client->request('GET', '/en/request-a-callback');

        $labels = $crawler->filter('fieldset[data-name="callback[rooms]"] label span[aria-hidden="true"]')->each(static fn ($span): string => $span->text());
        self::assertSame(['Studio', "1\u{00A0}bedroom", "2\u{00A0}bedrooms", "3\u{00A0}bedrooms", "4+\u{00A0}bedrooms"], $labels);
    }

    public function testContactSlotsAreMomentsOfTheDayThatNeverExpire(): void
    {
        $crawler = $this->client->request('GET', self::PATH);

        // The page is cached: no "now" nor "this afternoon" that would turn false later in the day
        self::assertSame(
            ['asap', 'morning', 'afternoon', 'evening'],
            $crawler->filter('input[name="callback[contactSlot]"]')->each(static fn ($radio): string => (string) $radio->attr('value')),
        );
        self::assertSelectorTextContains('fieldset[data-name="callback[contactSlot]"] legend', 'Quand pouvons-nous vous appeler ?');
        self::assertSelectorTextContains('fieldset[data-name="callback[contactSlot]"]', 'du lundi au vendredi de 8h à 20h, et le samedi de 8h à 12h');
    }

    public function testSearchAnswersAreRequired(): void
    {
        $values = self::validValues();
        unset($values['callback[furnishing]'], $values['callback[rooms]'], $values['callback[rentBudget]'], $values['callback[guarantor]']);

        $this->submit($values);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Veuillez indiquer le type de logement recherché.', $content);
        self::assertStringContainsString('Veuillez indiquer le nombre de pièces souhaité.', $content);
        self::assertStringContainsString('Veuillez indiquer si vous avez un garant.', $content);
        self::assertStringContainsString('Veuillez indiquer votre loyer maximum.', $content);
        self::assertSame([], $this->em->getRepository(Contact::class)->findAll());
    }

    public function testSettleTimingIsRequired(): void
    {
        $values = self::validValues();
        unset($values['callback[settleTiming]']);

        $this->submit($values);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Veuillez indiquer quand vous souhaitez vous installer.', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->em->getRepository(Contact::class)->findAll());
    }

    public function testInvalidTurboSubmissionReplacesTheFormWith200AndSendsNothing(): void
    {
        $values = self::validValues();
        unset($values['callback[situation]']);
        unset($values['callback[offer]']);

        $this->submit($values);

        // 200, not 422: o2switch replaces 4xx pages with its own, Turbo could not render the errors
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="callback-form"', $content);
        self::assertStringContainsString('Veuillez sélectionner votre situation.', $content);
        self::assertStringContainsString('Veuillez choisir une formule.', $content);
        self::assertSame([], $this->em->getRepository(Contact::class)->findAll());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testTheFormIsSplitIntoThreeStepsInsideASingleForm(): void
    {
        $crawler = $this->client->request('GET', self::PATH);

        $form = $crawler->filter('form[name="callback"][data-controller~="form-steps"]');
        self::assertCount(1, $form);
        $steps = $form->filter('[data-form-steps-target="step"]');
        self::assertCount(3, $steps);

        $fieldsOf = static fn (int $i): array => array_values(array_unique($steps->eq($i)->filter('input[name^="callback["], select[name^="callback["]')
            ->each(static fn ($field): string => (string) $field->attr('name'))));
        // Easy questions first, the price question last
        self::assertSame(['callback[situation]', 'callback[settleTiming]', 'callback[offer]'], $fieldsOf(0));
        // Step 2: the search; step 3: how and when to reach the visitor, then the contact details
        self::assertSame(['callback[furnishing]', 'callback[rooms]', 'callback[rentBudget]', 'callback[guarantor]'], $fieldsOf(1));
        self::assertSame(['callback[contactMethod]', 'callback[contactSlot]', 'callback[firstName]', 'callback[lastName]', 'callback[phoneNumber]', 'callback[email]', 'callback[accept]'], $fieldsOf(2));

        // The progress bar shows the position: counter for screen readers only, step 1 has no visible header
        self::assertSame(['Étape 1 sur 3', 'Étape 2 sur 3', 'Étape 3 sur 3'], $steps->filter('[data-testid="step-counter"]')->each(
            static fn ($counter): string => trim($counter->text()),
        ));
        $steps->filter('[data-testid="step-counter"]')->each(static fn ($counter) => self::assertStringContainsString('sr-only', (string) $counter->attr('class')));
        foreach ([1, 2, 3] as $i) {
            self::assertStringContainsString('sr-only', (string) $steps->eq($i - 1)->filter('#callback-step-'.$i)->ancestors()->eq(1)->attr('class'), 'No visible step title');
        }
        // Only the last step submits; the JS-only controls are hidden until the controller runs
        // No "most popular" cue on the packages
        self::assertCount(0, $form->filter('[data-testid="offer-popular"]'));
        self::assertStringNotContainsString('Le plus populaire', $form->text());
        // Premium package listed first, nothing preselected
        self::assertSame(['confie', 'accompagne'], $form->filter('input[name="callback[offer]"]')->each(static fn ($radio): string => (string) $radio->attr('value')));
        self::assertCount(0, $form->filter('input[name="callback[offer]"][checked]'));
        self::assertEqualsCanonicalizing(Contact::OFFERS, $form->filter('input[name="callback[offer]"]')->each(static fn ($radio): string => (string) $radio->attr('value')), 'Every package stays offered');
        self::assertCount(1, $form->filter('button[type="submit"]'));
        self::assertCount(1, $steps->eq(2)->filter('button[type="submit"]'));
        self::assertCount(2, $form->filter('button[data-action="form-steps#next"]'));
        $form->filter('[data-form-steps-target="jsOnly"]')->each(
            static fn ($control) => self::assertStringContainsString('hidden', (string) $control->attr('class')),
        );
    }

    public function testInvalidTurboSubmissionMarksErrorsSoTheStepWithTheErrorReopens(): void
    {
        $values = self::validValues();
        $values['callback[email]'] = '';

        $this->submit($values);

        self::assertResponseIsSuccessful();
        $steps = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('template [data-form-steps-target="step"]');
        self::assertCount(3, $steps);
        self::assertCount(0, $steps->eq(0)->filter('[data-form-steps-target="error"]'));
        self::assertCount(0, $steps->eq(1)->filter('[data-form-steps-target="error"]'));
        self::assertStringContainsString('Veuillez renseigner votre adresse email.', $steps->eq(2)->filter('[data-form-steps-target="error"]')->text());
    }

    public function testInvalidSubmissionWithoutJavascriptAnswers422(): void
    {
        $values = self::validValues();
        $values['callback[email]'] = '';

        $this->submit($values, turbo: false);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="callback"]', 'Veuillez renseigner votre adresse email.');
    }

    public function testItRejectsSubmissionWithInvalidCsrfToken(): void
    {
        $values = self::validValues();
        $values['callback[_token]'] = 'forged-token';

        $this->submit($values);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="callback-form"', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->em->getRepository(Contact::class)->findAll());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testHoneypotSubmissionIsRejectedSilently(): void
    {
        $values = self::validValues();
        $values['callback[website]'] = 'https://spam.example';

        $this->submit($values);

        self::assertSame([], $this->em->getRepository(Contact::class)->findAll());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testSubmissionsAreRateLimited(): void
    {
        // Deterministic: a 1-submission in-memory limiter instead of the shared cache one,
        // kept across both requests (no kernel reboot between them)
        $this->client->disableReboot();
        static::getContainer()->set('limiter.form_contact', new RateLimiterFactory(
            ['id' => 'form_contact_test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        $this->submit(self::validValues());
        self::assertResponseRedirects(self::PATH.'?success=1', Response::HTTP_SEE_OTHER);

        $this->submit(self::validValues());
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertCount(1, $this->em->getRepository(Contact::class)->findAll());
    }

    public function testConfirmationShowsTheChosenSlotInsideTheFormCard(): void
    {
        $this->submit(self::validValues());
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        // The chosen moment replaces the "30 minutes" promise, the number is the WhatsApp one
        self::assertSelectorTextSame('[data-testid="success-slot"]', "L'après-midi (12h - 17h)");
        self::assertSelectorTextContains('[role="status"]', 'Nous vous appelons sur WhatsApp au');
        self::assertSelectorTextNotContains('[role="status"]', '30 minutes maximum');
        // Same card as the form: border + background
        self::assertCount(1, $crawler->filter('div.rounded-2xl.border > [role="status"].rounded-xl'));
        // A way out once the request is done
        self::assertSame('/', $crawler->filter('[data-testid="success-home"]')->attr('href'));
        self::assertSelectorTextSame('[data-testid="success-home"]', "Revenir à l'accueil");
    }

    public function testConfirmationIsNeverCachedNorIndexed(): void
    {
        $this->client->request('GET', self::PATH.'?success=1');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-LiteSpeed-Cache-Control', 'no-cache');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
        self::assertSelectorExists('[role="status"]');
        self::assertSelectorNotExists('form[name="callback"]');
        self::assertStringEndsWith(self::PATH, (string) $this->client->getCrawler()->filter('link[rel="canonical"]')->attr('href'));
    }
}
