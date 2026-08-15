<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierSearch;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Entity\Visit;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Visit:VisitForm quick-booking behaviour: creation (with Places coordinates
 * or fallback geocoding), the detail-mode toggle, and validation failures.
 */
final class VisitFormTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;
    private Dossier $dossier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-form-test.local')->execute();

        $this->dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            // Booking a visit requires complete search criteria.
            ->setSearch($this->completeSearch());
        $this->em->persist($this->dossier);
        $this->em->flush();

        $this->loginAsAdmin();
    }

    public function testCreatesAVisitAndRedirectsToTheList(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        $response = $component->create($this->em, $this->nullGeocoder());
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertMatchesRegularExpression('~/'.self::PREFIX.'/admin/(visites|visits)$~', (string) $response->getTargetUrl());

        /** @var Visit|null $visit */
        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame($this->dossier->getId(), $visit->getDossier()?->getId());
        self::assertNotNull($visit->getBookedBy(), 'The operator who booked is stamped at creation.');
        self::assertNotNull($visit->getScheduledAt());
        self::assertNull($visit->getLatitude(), 'No key, no preview: coordinates stay null.');
    }

    public function testSelectingAPlaceStoresTheCoordinates(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        // Fired by the places-autocomplete trigger after a suggestion pick.
        $component->chooseLocation(48.8553, 2.3765);

        self::assertSame(48.8553, $component->previewLat);
        self::assertSame(2.3765, $component->previewLng);
        self::assertSame('12 rue de la Roquette, 75011 Paris', $component->locatedAddress);
    }

    public function testEditingTheAddressAfterASelectionDropsTheStaleCoordinates(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $component->chooseLocation(48.8553, 2.3765);

        // Address edited by hand after the pick: the stored coordinates no
        // longer match, creation must re-geocode instead of reusing them.
        $component->formValues['address'] = '14 rue de la Roquette, 75011 Paris';
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->geocoderReturning(48.86, 2.37)));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '14 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame(48.86, $visit->getLatitude());
        self::assertSame(2.37, $visit->getLongitude());
    }

    public function testCreateReusesThePreviewCoordinates(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        $component->chooseLocation(48.8553, 2.3765);
        // The geocoder must not be called at creation: Places already gave
        // the coordinates for this exact address.
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->failingGeocoder()));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame(48.8553, $visit->getLatitude());
        self::assertSame(2.3765, $visit->getLongitude());
    }

    public function testCreateFallsBackToServerGeocodingWithoutASelection(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        // Address typed by hand, never picked in the dropdown.
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->geocoderReturning(48.8553, 2.3765)));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame(48.8553, $visit->getLatitude());
        self::assertSame(2.3765, $visit->getLongitude());
    }

    public function testToggleDetailsFlipsTheDetailMode(): void
    {
        $component = $this->mountComponent();

        self::assertFalse($component->detailed, 'The form opens in quick mode.');
        $component->toggleDetails();
        self::assertTrue($component->detailed);
        $component->toggleDetails();
        self::assertFalse($component->detailed);
    }

    public function testPastScheduleBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: (new \DateTimeImmutable('-2 hours'))->format('Y-m-d\TH:i'));

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testMissingDossierBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(dossier: '');

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testTheFormStartsWithTheDossierFieldAlone(): void
    {
        // Sans dossier choisi, rien d'autre à remplir : ni le bien, ni le
        // créneau, ni le bouton de planification.
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="visit-form-dossier"', $rendered);
        // Le changement de dossier a son loader live, masqué au repos.
        self::assertStringContainsString('data-loading="model(visit.dossier)|removeClass(hidden)"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-dossier-recap"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-address"', $rendered);
        self::assertStringNotContainsString('data-testid="assignee-chips"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-details-toggle"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-submit"', $rendered);
    }

    public function testPickingADossierRevealsItsRecapCards(): void
    {
        $rendered = $this->renderWithDossier();

        self::assertStringContainsString('data-testid="visit-form-dossier-recap"', $rendered);
        // La formule pilote ce qu'on fait sur place : elle ouvre le rappel.
        self::assertStringContainsString('data-testid="visit-form-dossier-offer"', $rendered);
        self::assertStringContainsString('Famille Martin', $rendered);
        // Pendant un changement de dossier, tout ce qui décrit le dossier se
        // masque le temps du re-rendu (récap, bandes suivantes, submit).
        self::assertStringContainsString('data-loading="model(visit.dossier)|addClass(hidden)"', $rendered);
        // La colonne récap de droite apparaît avec le dossier choisi.
        self::assertStringContainsString('data-testid="visit-form-recap"', $rendered);
    }

    public function testQuickModeMarkupHidesTheDetailFields(): void
    {
        $rendered = $this->renderWithDossier();

        self::assertStringContainsString('data-testid="visit-form"', $rendered);
        // Who performs the visit and the notes stay out of the toggle.
        self::assertStringContainsString('data-testid="assignee-chips"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-note"', $rendered);
        // Only the optional fields sit behind the toggle.
        self::assertStringContainsString('data-testid="visit-form-details-toggle"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-details"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-client-present"', $rendered);
        // The address field carries the shared Places autocomplete, whose
        // selection trigger feeds the chooseLocation action.
        self::assertStringContainsString('data-controller="places-autocomplete"', $rendered);
        self::assertStringContainsString('data-places-autocomplete-target="results"', $rendered);
        self::assertStringContainsString('data-live-action-param="chooseLocation"', $rendered);
        // Anti double-submit guard on the create action, plus the button
        // hides while a dossier change re-renders the form.
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled) model(visit.dossier)|addClass(hidden)"', $rendered);
    }

    public function testDetailModeMarkupRendersTheOptionalFields(): void
    {
        $rendered = $this->renderWithDossier(detailed: true);

        self::assertStringContainsString('data-testid="visit-form-details"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-agent"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-listingUrl"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-client-present"', $rendered);
    }

    public function testAssigneeIsSavedWithTheVisit(): void
    {
        $staff = (new User())
            ->setEmail('staff+'.bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName('Marie')->setLastName('Curie')
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_VISITS'])->setPassword('x')
            ->setStaffFunctions([\App\Auth\Domain\StaffFunction::VisitAgent])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();

        $component = $this->mountComponent();
        // Le picker en chips expose uniquement les agents de visite.
        self::assertSame([$staff->getId()], array_column($component->getAssigneeChoices(), 'id'));

        $component->pickAssignee((int) $staff->getId());
        $component->formValues = $this->values(assignee: (string) $staff->getId());
        $component->create($this->em, $this->nullGeocoder());

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame($staff->getId(), $visit->getAssignee()?->getId());
    }

    public function testAStaffWithoutTheVisitAgentFunctionCannotBePicked(): void
    {
        $staff = (new User())
            ->setEmail('nofn+'.bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName('Sans')->setLastName('Fonction')
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_VISITS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();

        $component = $this->mountComponent();
        self::assertSame([], $component->getAssigneeChoices(), 'No visit-agent function, no chip.');

        // Un id forgé hors liste est rejeté par le formulaire : rien ne part.
        $component->formValues = $this->values(assignee: (string) $staff->getId());
        try {
            $component->create($this->em, $this->nullGeocoder());
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected: invalid choice.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testAddressOutsideIleDeFranceIsRejected(): void
    {
        $component = $this->mountComponent();
        // Pas de coordonnées (géocodeur nul) : le code postal fait foi.
        $component->formValues = $this->values(address: '12 rue de la République, 69001 Lyon');

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('An address outside Île-de-France must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));

        // Coordonnées géocodées hors de la boîte IDF : refus aussi, même si
        // l'adresse ne trahit pas sa région.
        $component = $this->mountComponent();
        $component->formValues = $this->values(address: 'Grande rue, quelque part');
        try {
            $component->create($this->em, $this->geocoderReturning(45.75, 4.85));
            self::fail('Coordinates outside Île-de-France must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testItRefusesToBookOnADossierWithIncompleteSearchCriteria(): void
    {
        $incomplete = (new Dossier())
            ->setName('Dossier a completer')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            // Budget seul : les six autres critères manquent.
            ->setSearch((new DossierSearch())->setBudget(2500));
        $this->em->persist($incomplete);
        $this->em->flush();

        // Le formulaire s'arrête au choix du dossier et explique pourquoi.
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => (new Visit())->setDossier($incomplete),
        ]);
        self::assertStringContainsString('data-testid="visit-form-dossier-incomplete"', $rendered);
        // Le lien ouvre le dossier directement sur l'onglet Recherche.
        self::assertStringContainsString('tab=search', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-submit"', $rendered);

        // Garde serveur : l'appel direct au endpoint live est refusé.
        $component = $this->mountComponent();
        $component->formValues = $this->values(dossier: (string) $incomplete->getId());
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Booking on an incomplete dossier must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));

        // Recherche complétée : la réservation repasse.
        $incomplete->getSearch()
            ?->setAreas('11e, 12e')
            ->setMoveInAt(new \DateTimeImmutable('+3 months'))
            ->setPropertyType('t2,t3')
            ->setStayDuration('long')
            ->setFurnishing('furnished')
            ->setGuarantorType('physical');
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = $this->values(dossier: (string) $incomplete->getId());
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    /** Les sept critères que DossierSearch::isComplete() exige. */
    private function completeSearch(): DossierSearch
    {
        return (new DossierSearch())
            ->setBudget(2500)
            ->setAreas('11e, 12e')
            ->setMoveInAt(new \DateTimeImmutable('+3 months'))
            ->setPropertyType('t2,t3')
            ->setStayDuration('long')
            ->setFurnishing('furnished')
            ->setGuarantorType('physical');
    }

    private function values(
        ?string $dossier = null,
        string $agent = '',
        string $assignee = '',
        ?string $scheduledAt = null,
        string $address = '12 rue de la Roquette, 75011 Paris',
        string $note = '',
    ): array {
        return [
            'dossier' => $dossier ?? (string) $this->dossier->getId(),
            'assignee' => $assignee,
            'agent' => $agent,
            'type' => 'property_visit',
            'durationMinutes' => '30',
            'listingUrl' => '',
            'clientPresent' => '1',
            // Future by default: past slots are rejected since the
            // "no scheduling in the past" constraint.
            'scheduledAt' => $scheduledAt ?? (new \DateTimeImmutable('+9 days'))->format('Y-m-d\TH:i'),
            'address' => $address,
            'note' => $note,
        ];
    }

    /** Geocoder with no key: short-circuits to null without any request. */
    private function nullGeocoder(): AddressGeocoder
    {
        return new AddressGeocoder(new MockHttpClient(), '');
    }

    private function geocoderReturning(float $lat, float $lng): AddressGeocoder
    {
        return new AddressGeocoder(new MockHttpClient(new MockResponse(json_encode([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]]]],
        ], \JSON_THROW_ON_ERROR))), 'test-key');
    }

    private function failingGeocoder(): AddressGeocoder
    {
        return new AddressGeocoder(new MockHttpClient(function (): MockResponse {
            self::fail('The geocoder must not be called here.');
        }), 'test-key');
    }

    /** Rendu du formulaire avec un dossier déjà choisi. */
    private function renderWithDossier(bool $detailed = false): string
    {
        $visit = (new Visit())->setDossier($this->dossier);

        return (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $visit,
            'detailed' => $detailed,
        ]);
    }

    private function mountComponent(): object
    {
        return $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => self::PREFIX]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
