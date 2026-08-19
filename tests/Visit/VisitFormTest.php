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
 * or fallback geocoding), the collapsible optional sections (contacts,
 * property details, photos), and validation failures.
 */
final class VisitFormTest extends KernelTestCase
{
    use InteractsWithTwigComponents;
    use \Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

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

    public function testSectionTogglesFlipEachSectionIndependently(): void
    {
        $component = $this->mountComponent();

        self::assertFalse($component->contactsOpen, 'Every optional section starts collapsed.');
        self::assertFalse($component->propertyDetailsOpen);
        self::assertFalse($component->photosOpen);

        $component->toggleContacts();
        self::assertTrue($component->contactsOpen);
        self::assertFalse($component->propertyDetailsOpen, 'Toggles are independent.');

        $component->togglePropertyDetails();
        $component->togglePhotos();
        self::assertTrue($component->propertyDetailsOpen);
        self::assertTrue($component->photosOpen);

        $component->toggleContacts();
        $component->togglePropertyDetails();
        $component->togglePhotos();
        self::assertFalse($component->contactsOpen);
        self::assertFalse($component->propertyDetailsOpen);
        self::assertFalse($component->photosOpen);
    }

    public function testRecapMoreToggleFlipsTheStateAndRendersTheCollapsedBlock(): void
    {
        // Non-régression (août 2026) : le bouton "Voir plus" du récap
        // appelait une action toggleRecapMore inexistante (clic sans effet).
        $component = $this->mountComponent();
        self::assertFalse($component->recapMoreOpen, 'The recap extra block starts collapsed.');

        $component->toggleRecapMore();
        self::assertTrue($component->recapMoreOpen);
        $component->toggleRecapMore();
        self::assertFalse($component->recapMoreOpen);

        // Replié : le bouton est là, câblé sur l'action, complément absent.
        $closed = $this->renderWithDossier();
        self::assertStringContainsString('data-testid="visit-form-recap-more"', $closed);
        self::assertStringContainsString('data-live-action-param="toggleRecapMore"', $closed);
        self::assertStringNotContainsString('data-testid="visit-form-recap-client-present"', $closed);

        // Déplié : le complément (présence client, etc.) se rend.
        $open = $this->renderWithDossier(recapMoreOpen: true);
        self::assertStringContainsString('data-testid="visit-form-recap-client-present"', $open);
    }

    public function testAListingUrlOver500CharsBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $values = $this->values();
        $values['listingUrl'] = 'https://www.seloger.com/'.str_repeat('a', 500);
        $component->formValues = $values;

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
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
        self::assertStringNotContainsString('data-testid="visit-form-contacts-toggle"', $rendered);
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
        // La ligne Oui/Non du récap est traduite : une clé brute à l'écran
        // signale une traduction manquante (non-régression, août 2026).
        self::assertStringNotContainsString('admin.visits.create.recap.yes', $rendered);
        self::assertStringNotContainsString('admin.visits.create.recap.no', $rendered);
    }

    public function testOptionalSectionsRenderCollapsedByDefault(): void
    {
        $rendered = $this->renderWithDossier();

        self::assertStringContainsString('data-testid="visit-form"', $rendered);
        // Who performs the visit and the notes stay out of the sections.
        self::assertStringContainsString('data-testid="assignee-chips"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-note"', $rendered);
        // The three collapsible bands are there, all closed: toggles present,
        // contents absent.
        self::assertStringContainsString('data-testid="visit-form-contacts-toggle"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-property-details-toggle"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-photos-toggle"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-contacts"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-property-details"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-photos"', $rendered);
        // La tuile "client présent" vit dans la bande Effectuée par (hors
        // sections) : visible même tout replié.
        self::assertStringContainsString('data-testid="visit-form-client-present"', $rendered);
        // The address field carries the shared Places autocomplete, whose
        // selection trigger feeds the chooseLocation action.
        self::assertStringContainsString('data-controller="places-autocomplete"', $rendered);
        self::assertStringContainsString('data-places-autocomplete-target="results"', $rendered);
        self::assertStringContainsString('data-live-action-param="chooseLocation"', $rendered);
        // Anti double-submit guard on the validation action, plus the button
        // hides while a dossier change re-renders the form. The submit only
        // validates and opens the confirm modal; creation runs from there.
        self::assertStringContainsString('data-loading="action(askCreate)|addAttribute(disabled) model(visit.dossier)|addClass(hidden)"', $rendered);
        self::assertStringContainsString('data-live-action-param="askCreate"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-confirm-modal"', $rendered);
    }

    public function testOpenContactsSectionRendersItsOptionalFields(): void
    {
        $rendered = $this->renderWithDossier(contactsOpen: true);

        self::assertStringContainsString('data-testid="visit-form-contacts"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-agent"', $rendered);
        // La recherche du dropdown agent est traduite (placeholder + état
        // vide) : la clé brute trahirait une traduction manquante.
        self::assertStringNotContainsString('admin.visits.create.agent.search', $rendered);
        // The other sections keep their own state.
        self::assertStringNotContainsString('data-testid="visit-form-property-details"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-photos"', $rendered);
    }

    public function testOpenPropertyDetailsSectionRendersTheFieldsAndChips(): void
    {
        $rendered = $this->renderWithDossier(propertyDetailsOpen: true);

        self::assertStringContainsString('data-testid="visit-form-property-details"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-surface"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-floor"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-rentExcludingCharges"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-charges"', $rendered);
        // Chips single toggle-off, dossier-search vocabulary.
        self::assertStringContainsString('data-testid="visit-form-propertyKind-t2"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-furnishing-furnished"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-leaseType-mobility"', $rendered);
        // L'annonce décrit le bien : son lien vit dans cette section.
        self::assertStringContainsString('data-testid="visit-form-listingUrl"', $rendered);
    }

    public function testOpenPhotosSectionRendersTheUploadZone(): void
    {
        $rendered = $this->renderWithDossier(photosOpen: true);

        self::assertStringContainsString('data-testid="visit-form-photos"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-photos-input"', $rendered);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $rendered);
        // The upload zone is client-side only: shielded from the live morph.
        self::assertStringContainsString('data-live-ignore', $rendered);
        // The submit validates and opens the confirm modal; the files ride
        // the create action fired from the modal button.
        self::assertStringContainsString('data-live-action-param="askCreate"', $rendered);
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

    public function testEmptyStateShowsTheCenteredIntro(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
        ]);

        // Accueil centré tant qu'aucun dossier n'est choisi : consigne,
        // règle de la recherche validée et compteur de dossiers proposables.
        self::assertStringContainsString('data-testid="visit-form-intro"', $rendered);
        // Le reste du formulaire attend le choix du dossier.
        self::assertStringNotContainsString('data-testid="visit-form-submit"', $rendered);
    }

    public function testDossierPickerOnlyListsOpenDossiersWithACompleteSearch(): void
    {
        // La recherche incomplète (budget seul) exclut le dossier de la liste.
        $incomplete = (new Dossier())
            ->setName('Dossier a completer')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSearch((new DossierSearch())->setBudget(2500));
        $closed = (new Dossier())
            ->setName('Dossier Clos')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setClosedAt(new \DateTimeImmutable())
            ->setSearch($this->completeSearch());
        $this->em->persist($incomplete);
        $this->em->persist($closed);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
        ]);

        // Seul le dossier ouvert à recherche complète (setUp) est proposé.
        self::assertStringContainsString('Famille Martin', $rendered);
        self::assertStringNotContainsString('Dossier a completer', $rendered);
        self::assertStringNotContainsString('Dossier Clos', $rendered);
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

    public function testSameDossierSameAddressSameDayIsRefused(): void
    {
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(10, 0);
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $slot->format('Y-m-d\TH:i'));
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        // Même dossier, même adresse, même jour (autre heure) : refusé net.
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $slot->setTime(16, 30)->format('Y-m-d\TH:i'));
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Booking the same dossier on the same address the same day must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));

        // Un autre jour (contre-visite) reste possible.
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $slot->modify('+2 days')->format('Y-m-d\TH:i'));
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(2, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testDuplicateBannerFlagsTheSameDossierMatch(): void
    {
        // Une visite existe déjà pour ce dossier à cette adresse.
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        $prefilled = (new Visit())
            ->setDossier($this->dossier)
            ->setAddress('12 rue de la Roquette, 75011 Paris');
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $prefilled,
        ]);

        self::assertStringContainsString('data-testid="visit-duplicate-warning"', $rendered);
        self::assertStringContainsString('data-testid="visit-duplicate-same-dossier"', $rendered);
    }

    public function testAgentDropdownShowsIdentityAgencyAndBrandAndTogglesOff(): void
    {
        // Nom unique : la DB de test est partagée et l'enseigne est unique.
        $brandName = 'Orpi-'.bin2hex(random_bytes(3));
        $brand = new \App\RealEstateAgent\Entity\Brand($brandName);
        $agency = (new Agency())->setName('Orpi Bastille '.$brandName)->setBrand($brand)->setCreatedAt(new \DateTimeImmutable());
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setAgency($agency);
        $agent->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($brand);
        $this->em->persist($agency);
        $this->em->persist($agent);
        $this->em->flush();

        $rendered = $this->renderWithDossier(contactsOpen: true);
        // Ligne riche : nom + agence + enseigne dans le panel du dropdown.
        self::assertStringContainsString('data-testid="visit-form-agent-option-'.$agent->getId().'"', $rendered);
        self::assertStringContainsString('Orpi Bastille '.$brandName.' · '.$brandName, $rendered);

        $component = $this->mountComponent();
        $component->chooseAgent((int) $agent->getId());
        self::assertSame((string) $agent->getId(), $component->formValues['agent']);
        self::assertSame('Jean Martin', $component->getSelectedAgent()?->fullName);
        // Re-clic = retiré; id inconnu = rejeté.
        $component->chooseAgent((int) $agent->getId());
        self::assertSame('', $component->formValues['agent']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $component->chooseAgent(999999);
    }

    public function testAccompaniedOfferMakesTheClientTheVisitor(): void
    {
        $this->dossier->setOffer('accompagne');
        $this->em->flush();

        // Markup : note "le client visite lui-même", pas de chips d'assignation
        // ni de toggle de présence.
        $rendered = $this->renderWithDossier(contactsOpen: true);
        self::assertStringContainsString('data-testid="visit-form-client-self"', $rendered);
        self::assertStringNotContainsString('data-testid="assignee-chips"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-client-present"', $rendered);

        // Serveur : un assigné forgé dans le POST est écrasé, présence forcée.
        $staff = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName('Julien')
            ->setLastName('Moreau')
            ->setPassword('x')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setStaffFunctions([\App\Auth\Domain\StaffFunction::VisitAgent]);
        $this->em->persist($staff);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = $this->values(assignee: (string) $staff->getId());
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        $visit = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertNull($visit->getAssignee());
        self::assertTrue($visit->isClientPresent());
    }

    public function testInventoryAndTechnicalInterventionNeedATeamMember(): void
    {
        // Même en formule Accompagné : un état des lieux ne se fait pas seul.
        $this->dossier->setOffer('accompagne');
        $this->em->flush();

        $component = $this->mountComponent();
        $values = $this->values();
        $values['type'] = 'inventory';
        $component->formValues = $values;
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('An inventory without a team member must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));

        // Avec un membre désigné, la création passe et il est conservé
        // (l'écrasement Accompagné ne vaut que pour une visite de bien).
        $staff = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName('Julien')
            ->setLastName('Moreau')
            ->setPassword('x')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setStaffFunctions([\App\Auth\Domain\StaffFunction::VisitAgent]);
        $this->em->persist($staff);
        $this->em->flush();

        $component = $this->mountComponent();
        $values = $this->values(assignee: (string) $staff->getId());
        $values['type'] = 'technical_intervention';
        $component->formValues = $values;
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $visit = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertSame((int) $staff->getId(), (int) $visit->getAssignee()?->getId());
    }

    public function testAssigneeConflictIsDetectedOnAPartialOverlap(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        // Visite existante 14h00-14h30 pour Marie.
        $this->persistVisit($staff, $slot, 30);

        // Nouveau créneau 14h15-14h45 : chevauchement partiel.
        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 15)->format('Y-m-d\TH:i'),
        );

        $conflicts = $component->getAssigneeConflicts();
        self::assertCount(1, $conflicts);
        self::assertSame('Marie Curie', $conflicts[0]->assigneeName);
    }

    public function testBackToBackVisitsDoNotConflict(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        // 14h00-14h30 existante, nouveau créneau 14h30-15h00 : dos à dos.
        $this->persistVisit($staff, $slot, 30);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 30)->format('Y-m-d\TH:i'),
        );

        self::assertSame([], $component->getAssigneeConflicts());
    }

    public function testCancelledVisitsNeverConflict(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        // Chevauchement franc, mais la visite existante est annulée.
        $this->persistVisit($staff, $slot, 30, \App\Visit\Domain\VisitStatus::Cancelled);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 15)->format('Y-m-d\TH:i'),
        );

        self::assertSame([], $component->getAssigneeConflicts());
    }

    public function testAssigneeConflictBannerRendersUnderTheChips(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        $existing = $this->persistVisit($staff, $slot, 30);

        // Formulaire prérempli sur le même créneau avec la même assignée.
        $prefilled = (new Visit())
            ->setDossier($this->dossier)
            ->setAssignee($staff)
            ->setScheduledAt($slot->setTime(14, 15))
            ->setDurationMinutes(30);
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $prefilled,
        ]);

        self::assertStringContainsString('data-testid="visit-form-assignee-conflict"', $rendered);
        self::assertStringContainsString('Marie Curie', $rendered);
        // Chaque conflit pointe vers sa fiche visite.
        self::assertStringContainsString('data-testid="visit-form-assignee-conflict-link"', $rendered);
        self::assertStringContainsString((string) $existing->getReference(), $rendered);
    }

    public function testOutOfAreaHintShowsWhenThePropertyLeavesTheSearchedDistricts(): void
    {
        // Recherche du setUp : 11e, 12e. Bien géolocalisé à Montmartre (18e).
        $component = $this->mountComponent();
        $component->formValues = $this->values(address: '10 rue Lamarck, 75018 Paris');
        $component->chooseLocation(48.8867, 2.3431);

        $hint = $component->getOutOfArea();
        self::assertNotNull($hint);
        self::assertSame('18e', $hint['district']);
        self::assertSame(['11e', '12e'], $hint['areas']);

        // Markup : le bandeau discret apparaît sous le champ adresse.
        $prefilled = (new Visit())
            ->setDossier($this->dossier)
            ->setAddress('10 rue Lamarck, 75018 Paris');
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $prefilled,
            'previewLat' => 48.8867,
            'previewLng' => 2.3431,
            'locatedAddress' => '10 rue Lamarck, 75018 Paris',
        ]);
        self::assertStringContainsString('data-testid="visit-form-out-of-area"', $rendered);
    }

    public function testOutOfAreaHintStaysSilentWhenTheDistrictMatchesTheSearch(): void
    {
        // Point au coeur du 11e : dans le secteur recherché, rien à signaler.
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $component->chooseLocation(48.8590, 2.3800);

        self::assertNull($component->getOutOfArea());
    }

    public function testOutOfAreaHintStaysSilentWithoutLocatedCoordinates(): void
    {
        // Adresse tapée à la main, jamais choisie dans l'autocomplete.
        $component = $this->mountComponent();
        $component->formValues = $this->values(address: '10 rue Lamarck, 75018 Paris');

        self::assertNull($component->getOutOfArea());
    }

    public function testOddHoursAreFlaggedAsUnusual(): void
    {
        $early = (new \DateTimeImmutable('+9 days'))->setTime(7, 30);
        $late = (new \DateTimeImmutable('+9 days'))->setTime(20, 0);
        $normal = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);

        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $early->format('Y-m-d\TH:i'));
        self::assertTrue($component->isOddHourSlot(), 'Before 08:00 is unusual.');

        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $late->format('Y-m-d\TH:i'));
        self::assertTrue($component->isOddHourSlot(), '20:00 and later is unusual.');

        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $normal->format('Y-m-d\TH:i'));
        self::assertFalse($component->isOddHourSlot(), '14:00 is a normal slot.');

        // Bornes exactes : 08:00 et 19:59 restent des créneaux normaux.
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $normal->setTime(8, 0)->format('Y-m-d\TH:i'));
        self::assertFalse($component->isOddHourSlot(), '08:00 sharp is a normal slot.');
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $normal->setTime(19, 59)->format('Y-m-d\TH:i'));
        self::assertFalse($component->isOddHourSlot(), '19:59 is a normal slot.');

        // Markup : le rappel doux apparaît sous le champ créneau.
        $prefilled = (new Visit())
            ->setDossier($this->dossier)
            ->setScheduledAt($early);
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $prefilled,
        ]);
        self::assertStringContainsString('data-testid="visit-form-odd-hour"', $rendered);
    }

    public function testChangingTheTypePrefillsTheEstimatedDuration(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        // property_visit -> inventory : la durée par défaut suit (30 -> 60).
        $component->formValues['type'] = 'inventory';
        $component->syncDurationWithType();
        self::assertSame('60', $component->formValues['durationMinutes']);

        // Retour en visite de bien : 60 est encore le défaut de l'inventaire,
        // donc la durée se réaligne sur 30.
        $component->formValues['type'] = 'property_visit';
        $component->syncDurationWithType();
        self::assertSame('30', $component->formValues['durationMinutes']);
    }

    public function testAManuallyChosenDurationSurvivesATypeChange(): void
    {
        $component = $this->mountComponent();
        $values = $this->values();
        // Durée choisie explicitement (15 n'est le défaut d'aucun type).
        $values['durationMinutes'] = '15';
        $component->formValues = $values;

        $component->formValues['type'] = 'inventory';
        $component->syncDurationWithType();

        self::assertSame('15', $component->formValues['durationMinutes'], 'An explicit duration choice is never overwritten.');
    }

    public function testPropertyDetailsArePersistedWithTheVisit(): void
    {
        $component = $this->mountComponent();
        $values = $this->values();
        $values['surface'] = '45.5';
        // 0 = rez-de-chaussée, une valeur légitime.
        $values['floor'] = '0';
        $values['propertyKind'] = 't2';
        $values['furnishing'] = 'furnished';
        $values['leaseType'] = 'mobility';
        $values['rentExcludingCharges'] = '1450';
        $values['charges'] = '150';
        $component->formValues = $values;

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertSame(45.5, $visit->getSurface());
        self::assertSame(0, $visit->getFloor());
        self::assertSame('t2', $visit->getPropertyKind());
        self::assertSame('furnished', $visit->getFurnishing());
        self::assertSame(\App\Visit\Domain\LeaseType::Mobility, $visit->getLeaseType());
        self::assertSame(1450.0, $visit->getRentExcludingCharges());
        self::assertSame(150.0, $visit->getCharges());
    }

    public function testPropertyDetailsDefaultToNull(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        self::assertNull($visit->getSurface());
        self::assertNull($visit->getFloor());
        self::assertNull($visit->getPropertyKind());
        self::assertNull($visit->getFurnishing());
        self::assertNull($visit->getLeaseType());
        self::assertNull($visit->getRentExcludingCharges());
        self::assertNull($visit->getCharges());
    }

    public function testAForgedPropertyKindIsRejectedByTheForm(): void
    {
        $component = $this->mountComponent();
        $values = $this->values();
        // "castle" n'est pas dans les choix : le form doit refuser.
        $values['propertyKind'] = 'castle';
        $component->formValues = $values;

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('An unknown property kind must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected: invalid choice.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testPropertyChipsToggleOffAndRejectUnknownValues(): void
    {
        $component = $this->mountComponent();

        $component->choosePropertyKind('t2');
        self::assertSame('t2', $component->formValues['propertyKind']);
        // Re-clic sur la chip active = désélection.
        $component->choosePropertyKind('t2');
        self::assertSame('', $component->formValues['propertyKind']);

        $component->chooseFurnishing('furnished');
        self::assertSame('furnished', $component->formValues['furnishing']);
        $component->chooseFurnishing('unfurnished');
        self::assertSame('unfurnished', $component->formValues['furnishing']);

        $component->chooseLeaseType('alur');
        self::assertSame('alur', $component->formValues['leaseType']);
        $component->chooseLeaseType('alur');
        self::assertSame('', $component->formValues['leaseType']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $component->choosePropertyKind('castle');
    }

    public function testPropertyRecapLineOnlyShowsTheEnteredValues(): void
    {
        $component = $this->mountComponent();
        $values = $this->values();
        $values['surface'] = '45.5';
        $values['floor'] = '6';
        $values['rentExcludingCharges'] = '1450';
        $values['charges'] = '150';
        $component->formValues = $values;

        $parts = $component->getPropertyRecapParts();
        self::assertCount(3, $parts);
        self::assertStringContainsString('m²', $parts[0]);
        self::assertStringContainsString('6', $parts[1]);
        self::assertStringContainsString('1', $parts[2]);

        // Rien de saisi : pas de ligne récap.
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        self::assertSame([], $component->getPropertyRecapParts());

        // Étage 0 = RDC, une valeur affichable à part entière.
        $component = $this->mountComponent();
        $values = $this->values();
        $values['floor'] = '0';
        $component->formValues = $values;
        self::assertCount(1, $component->getPropertyRecapParts());
    }

    public function testPhotosSentWithTheCreateActionAreStored(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $this->pushRequestWithPhotos([
            $this->pngUpload('salon.png'),
            $this->pngUpload('cuisine.png'),
        ]);

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        $visit = $this->em->getRepository(Visit::class)->findOneBy(['address' => '12 rue de la Roquette, 75011 Paris']);
        self::assertNotNull($visit);
        /** @var list<\App\Visit\Entity\VisitPhoto> $photos */
        $photos = $this->em->getRepository(\App\Visit\Entity\VisitPhoto::class)->findBy(['visit' => $visit->getId()]);
        self::assertCount(2, $photos);
        /** @var \App\Visit\Storage\VisitPhotoStorage $storage */
        $storage = self::getContainer()->get(\App\Visit\Storage\VisitPhotoStorage::class);
        foreach ($photos as $photo) {
            // Convention bucket : visits/<ref>/photos/<uuid>.<ext>.
            self::assertMatchesRegularExpression(
                '#^visits/'.$visit->getReference().'/photos/[0-9a-f-]{36}\.png$#',
                (string) $photo->getPath(),
            );
            self::assertTrue($storage->exists((string) $photo->getPath()));
            // Création = photos de l'annonce, prises avant la visite.
            self::assertSame('before', $photo->getPhase());
        }
    }

    public function testAnInvalidPhotoNeverBlocksTheVisitCreation(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'photo').'.txt';
        file_put_contents($bad, 'not an image');

        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $this->pushRequestWithPhotos([
            new \Symfony\Component\HttpFoundation\File\UploadedFile($bad, 'notes.txt', 'text/plain', test: true),
            $this->pngUpload('salon.png'),
        ]);

        // Best-effort : le fichier illisible est ignoré, la visite se crée
        // et la photo valide est conservée.
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
        self::assertSame(1, (int) $this->em->getRepository(\App\Visit\Entity\VisitPhoto::class)->count([]));
        @unlink($bad);
    }

    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function pngUpload(string $name): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'visit-photo').'.png';
        file_put_contents($path, base64_decode(self::PNG));

        return new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, 'image/png', test: true);
    }

    /**
     * Simule la requête live "files|create" : les fichiers voyagent dans la
     * requête courante, que le composant lit via le RequestStack partagé.
     *
     * @param list<\Symfony\Component\HttpFoundation\File\UploadedFile> $files
     */
    private function pushRequestWithPhotos(array $files): void
    {
        self::getContainer()->get('request_stack')->push(
            \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], ['photos' => $files]),
        );
    }

    public function testAssigneeOverlapBlocksCreation(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        $existing = $this->persistVisit($staff, $slot, 30);

        // Chevauchement franc 14h15-14h45 : refus net, plus seulement le
        // bandeau ambre informatif.
        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 15)->format('Y-m-d\TH:i'),
        );
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('An overlapping slot for the assignee must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }

        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]), 'Only the pre-existing visit remains.');
        $errors = $this->assigneeErrors($component);
        self::assertCount(1, $errors);
        self::assertStringContainsString((string) $existing->getReference(), (string) $errors[0]->getMessage());
        self::assertStringContainsString('Marie Curie', (string) $errors[0]->getMessage());
    }

    public function testAssigneeFreeOnAnotherDayPassesTheOverlapGuard(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        $this->persistVisit($staff, $slot, 30);

        // Même horaire un autre jour : aucune gêne.
        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->modify('+1 day')->format('Y-m-d\TH:i'),
        );

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(2, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testABusyGoogleAgendaBlocksCreation(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        // L'agenda Google de Marie est pris sur le créneau (visio de lead,
        // rendez-vous perso...), sans aucune visite en base.
        $slotUtc = new \DateTimeImmutable($slot->format('Y-m-d').' 14:00', new \DateTimeZone('Europe/Paris'));
        $this->installCalendarClient(busy: [[
            'start' => $slotUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'end' => $slotUtc->modify('+30 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]]);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 15)->format('Y-m-d\TH:i'),
        );
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('A busy Google agenda on the slot must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }

        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
        $errors = $this->assigneeErrors($component);
        self::assertCount(1, $errors);
        self::assertStringContainsString('agenda Google', (string) $errors[0]->getMessage());
        self::assertStringContainsString('Marie Curie', (string) $errors[0]->getMessage());
    }

    public function testADisjointBusyIntervalPassesAndTheVisitIsMirrored(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 0);
        // Occupé le matin seulement : le créneau de 14h passe.
        $morning = new \DateTimeImmutable($slot->format('Y-m-d').' 09:00', new \DateTimeZone('Europe/Paris'));
        $this->installCalendarClient(busy: [[
            'start' => $morning->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'end' => $morning->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]]);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->format('Y-m-d\TH:i'),
        );

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        /** @var Visit $visit */
        $visit = $this->em->getRepository(Visit::class)->findOneBy([]);
        // Double écriture au fil de la création : central + agenda de Marie.
        self::assertNotNull($visit->getCalendarCentralEventId());
        self::assertNotNull($visit->getCalendarAssigneeEventId());
        self::assertSame($staff->getEmail(), $visit->getCalendarAssigneeEmail());
    }

    public function testAFreeBusyOutageNeverBlocksCreation(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        // freeBusy en erreur 500 : disponibilité inconnue, on ne bloque que
        // sur le contrôle base (vide ici), jamais à cause du réseau.
        $this->installCalendarClient(busyStatus: 500);

        $component = $this->mountComponent();
        $component->formValues = $this->values(assignee: (string) $staff->getId());

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    /**
     * Erreurs du champ assigné, via réflexion : getForm() est privé dans
     * ComponentWithFormTrait et le formulaire n'est pas exposé autrement.
     */
    private function assigneeErrors(object $component): \Symfony\Component\Form\FormErrorIterator
    {
        $getForm = new \ReflectionMethod($component, 'getForm');

        return $getForm->invoke($component)->get('assignee')->getErrors();
    }

    /**
     * Remplace le client Calendar du conteneur par un client configuré sur
     * un HTTP mocké : freeBusy sert les intervalles donnés (ou l'erreur),
     * les upserts d'événements répondent des ids stables.
     *
     * @param list<array{start: string, end: string}> $busy
     */
    private function installCalendarClient(array $busy = [], int $busyStatus = 200): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $keyFile = (string) tempnam(sys_get_temp_dir(), 'gcal-form-');
        file_put_contents($keyFile, (string) json_encode([
            'client_email' => 'visio@service-account.test',
            'private_key' => $pem,
        ]));

        $sequence = 0;
        $http = new MockHttpClient(function (string $method, string $url) use ($busy, $busyStatus, &$sequence): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }
            if (str_contains($url, '/freeBusy')) {
                if ($busyStatus >= 400) {
                    return new MockResponse('', ['http_code' => $busyStatus]);
                }

                return new MockResponse((string) json_encode(['calendars' => ['primary' => ['busy' => $busy]]]));
            }
            if ('DELETE' === $method) {
                return new MockResponse('');
            }
            ++$sequence;

            return new MockResponse((string) json_encode(['id' => 'evt-'.$sequence]));
        });

        self::getContainer()->set(
            \App\Contact\Service\GoogleCalendarClient::class,
            new \App\Contact\Service\GoogleCalendarClient(
                $http,
                self::getContainer()->get('logger'),
                $keyFile,
                'agenda@relocation-in-paris.fr',
            ),
        );
    }

    private function makeVisitAgent(string $firstName, string $lastName): User
    {
        $staff = (new User())
            ->setEmail(strtolower($firstName).'+'.bin2hex(random_bytes(4)).'@visit-form-test.local')
            ->setFirstName($firstName)->setLastName($lastName)
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_VISITS'])->setPassword('x')
            ->setStaffFunctions([\App\Auth\Domain\StaffFunction::VisitAgent])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();

        return $staff;
    }

    /** Visite existante en base pour un assigné, sur un autre bien. */
    private function persistVisit(
        User $assignee,
        \DateTimeImmutable $scheduledAt,
        int $durationMinutes,
        \App\Visit\Domain\VisitStatus $status = \App\Visit\Domain\VisitStatus::Planned,
    ): Visit {
        $visit = (new Visit())
            ->setDossier($this->dossier)
            ->setAddress('5 avenue Daumesnil, 75012 Paris')
            ->setScheduledAt($scheduledAt)
            ->setDurationMinutes($durationMinutes)
            ->setAssignee($assignee)
            ->setStatus($status)
            ->setReference('VS-'.random_int(100000, 999999))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    public function testRentChargesIncludedHidesAndClearsTheChargesField(): void
    {
        // CC : la bascule vide le montant de charges déjà saisi.
        $component = $this->mountComponent();
        $component->formValues['charges'] = '150';
        $component->chooseRentMode('cc');
        self::assertSame('1', $component->formValues['rentChargesIncluded']);
        self::assertSame('', $component->formValues['charges']);
        try {
            $component->chooseRentMode('ttc');
            self::fail('Unknown rent mode must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
            // Expected.
        }

        // Serveur : en CC, un montant de charges forgé est abandonné.
        $component = $this->mountComponent();
        $values = $this->values();
        $values['rentExcludingCharges'] = '1450';
        $values['charges'] = '150';
        $values['rentChargesIncluded'] = '1';
        $component->formValues = $values;
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $visit = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertTrue($visit->getRentChargesIncluded());
        self::assertSame(1450.0, $visit->getRentExcludingCharges());
        self::assertNull($visit->getCharges());

        // Non-régression : l'input caché soumet '' en mode HC, qui doit bien
        // redonner "hors charges" (CheckboxType ne tient que null pour faux
        // par défaut, ce qui verrouillait le mode sur CC).
        $this->em->remove($visit);
        $this->em->flush();
        $component = $this->mountComponent();
        $values = $this->values();
        $values['rentExcludingCharges'] = '1450';
        $values['charges'] = '150';
        $values['rentChargesIncluded'] = '';
        $component->formValues = $values;
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $visit = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertNotTrue($visit->getRentChargesIncluded());
        self::assertSame(150.0, $visit->getCharges());
    }

    public function testAnExistingVisitSpillingOverMidnightConflicts(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        // Visite existante la veille 23h30 + 60 min : elle déborde jusqu'à
        // 00h30. Nouveau créneau 00h15 : chevauchement réel, que le préfiltre
        // SQL par journée ratait (la visite démarre la veille du créneau).
        $this->persistVisit($staff, (new \DateTimeImmutable('+8 days'))->setTime(23, 30), 60);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: (new \DateTimeImmutable('+9 days'))->setTime(0, 15)->format('Y-m-d\TH:i'),
        );

        self::assertCount(1, $component->getAssigneeConflicts(), 'A visit crossing midnight must be caught.');
    }

    public function testAnEarlierBackToBackSlotDoesNotConflict(): void
    {
        $staff = $this->makeVisitAgent('Marie', 'Curie');
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(14, 30);
        // Existante 14h30-15h00; nouveau créneau 14h00-14h30 juste avant :
        // dos à dos, pas de conflit. Non-régression : sans remise à zéro des
        // secondes du créneau saisi (format "!"), l'horloge courante glissait
        // quelques secondes fantômes dans l'intervalle et signalait un faux
        // chevauchement.
        $this->persistVisit($staff, $slot, 30);

        $component = $this->mountComponent();
        $component->formValues = $this->values(
            assignee: (string) $staff->getId(),
            scheduledAt: $slot->setTime(14, 0)->format('Y-m-d\TH:i'),
        );

        self::assertSame([], $component->getAssigneeConflicts());
    }

    public function testAManualDurationEqualToAnotherTypesDefaultSurvivesTheRoundTrip(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        // Durée choisie à la main sur une visite de bien : 60 se trouve être
        // aussi le défaut de l'état des lieux.
        $component->formValues['durationMinutes'] = '60';

        $component->formValues['type'] = 'inventory';
        $component->syncDurationWithType();
        self::assertSame('60', $component->formValues['durationMinutes']);

        // Retour en visite de bien : le choix manuel doit survivre (avant le
        // fix, 60 était pris pour le défaut de l'inventaire et réécrit à 30).
        $component->formValues['type'] = 'property_visit';
        $component->syncDurationWithType();
        self::assertSame('60', $component->formValues['durationMinutes'], 'A manual duration equal to another type default is never overwritten.');
    }

    public function testAClosedDossierIsNeitherPreselectedNorBookable(): void
    {
        $closed = (new Dossier())
            ->setName('Dossier Ferme Recemment')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setClosedAt(new \DateTimeImmutable())
            // Recherche complète : seule la clôture doit bloquer.
            ->setSearch($this->completeSearch());
        $this->em->persist($closed);
        $this->em->flush();

        // Arrivée avec ?dossier= sur un dossier clos : pas de présélection.
        $component = $this->mountTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'dossierId' => $closed->getId(),
        ]);
        self::assertNull($component->visit?->getDossier(), 'A closed dossier is never preselected.');

        // Présélection périmée (dossier clos entre l'ouverture du formulaire
        // et le submit) ou POST forgé : refus net à la création.
        $component = $this->mountComponent();
        $component->visit = (new Visit())->setDossier($closed);
        $component->formValues = $this->values(dossier: (string) $closed->getId());
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Booking on a closed dossier must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testEditingTheAddressByHandDropsTheOutOfAreaHint(): void
    {
        // Sélection Places dans le 18e (recherche du setUp : 11e, 12e), puis
        // adresse retouchée à la main : les coordonnées ne décrivent plus
        // l'adresse courante, l'alerte hors secteur doit disparaître.
        $component = $this->mountComponent();
        $component->formValues = $this->values(address: '10 rue Lamarck, 75018 Paris');
        $component->chooseLocation(48.8867, 2.3431);

        $component->formValues['address'] = '11 rue Lamarck, 75018 Paris';

        self::assertNull($component->getOutOfArea());
    }

    public function testAtMostTwelvePhotosAreStoredEvenOnAForgedPost(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        // 13 fichiers valides : le compteur client (12 max) est contournable,
        // la garde serveur doit couper à 12.
        $this->pushRequestWithPhotos(array_map(
            fn (int $i): \Symfony\Component\HttpFoundation\File\UploadedFile => $this->pngUpload(\sprintf('photo-%02d.png', $i)),
            range(1, 13),
        ));

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        self::assertSame(12, (int) $this->em->getRepository(\App\Visit\Entity\VisitPhoto::class)->count([]));
    }

    public function testTheConfirmModalOpensOnlyOnceTheFormIsValid(): void
    {
        // Formulaire invalide (adresse vide) : 422, la modale reste fermée.
        $component = $this->mountComponent();
        $component->formValues = $this->values(address: '');
        try {
            $component->askCreate();
            self::fail('An invalid form must not open the confirm modal.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertFalse($component->confirmingCreate);
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));

        // Formulaire valide : la modale s'ouvre, rien n'est encore créé.
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $component->askCreate();
        self::assertTrue($component->confirmingCreate);
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));

        // Annuler est l'unique chemin de sortie : la modale se referme.
        $component->cancelCreate();
        self::assertFalse($component->confirmingCreate);
    }

    public function testTheConfirmModalRendersTheNotifyToggleAndBothButtons(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => (new Visit())->setDossier($this->dossier),
            'confirmingCreate' => true,
        ]);

        self::assertStringContainsString('data-testid="visit-form-confirm-modal"', $rendered);
        // L'email de confirmation part par défaut (toggle coché).
        self::assertStringContainsString('data-testid="visit-form-confirm-notify"', $rendered);
        // norender : la bascule ne re-rend pas la modale ouverte, condition
        // de sûreté de l'animation d'entrée (le morph retirerait data-entered).
        self::assertStringContainsString('data-model="norender|notifyClient"', $rendered);
        self::assertStringContainsString('data-controller="modal-focus modal-anim instant-exit"', $rendered);
        // La création réelle (photos comprises) part du bouton de la modale.
        self::assertStringContainsString('data-live-action-param="files|create"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-confirm-submit"', $rendered);
        self::assertStringContainsString('data-testid="visit-form-confirm-cancel"', $rendered);
    }

    public function testAGuardFailureAtConfirmClosesTheModal(): void
    {
        // Une première visite occupe l'adresse ce jour-là.
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(10, 0);
        $component = $this->mountComponent();
        $component->formValues = $this->values(scheduledAt: $slot->format('Y-m-d\TH:i'));
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        // Confirm sur un double-booking : la garde échoue, la modale se
        // referme et l'erreur s'affiche dans le formulaire.
        $component = $this->mountComponent();
        $component->confirmingCreate = true;
        $component->formValues = $this->values(scheduledAt: $slot->setTime(16, 0)->format('Y-m-d\TH:i'));
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('The duplicate guard must still fire at confirm time.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        self::assertFalse($component->confirmingCreate);
    }

    public function testCreateLogsAVisitBookedEventOnTheDossierThread(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        /** @var list<\App\Dossier\Entity\DossierEvent> $events */
        $events = $this->em->getRepository(\App\Dossier\Entity\DossierEvent::class)->findBy([
            'dossier' => $this->dossier->getId(),
            'kind' => 'visit_booked',
        ]);
        self::assertCount(1, $events);
        self::assertSame('12 rue de la Roquette, 75011 Paris', $events[0]->getPayload()['value'] ?? null);
        self::assertNotSame('', (string) ($events[0]->getPayload()['date'] ?? ''), 'The slot rides the event payload.');
        self::assertNotNull($events[0]->getAuthorName(), 'The operator is snapshotted as author.');
    }

    public function testCreateEmailsEveryReachableDossierContactWhenNotifyIsTicked(): void
    {
        $person = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean@visit-form-test.local')
            ->setPrimaryContact(true);
        // Deuxième contact joignable (suivi d'entreprise) : il reçoit aussi.
        $second = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Claire')->setLastName('Dupont')
            ->setEmail('claire@visit-form-test.local');
        // Sans email exploitable (colonne NOT NULL, chaîne vide stockée) :
        // ignoré sans faire échouer l'envoi.
        $unreachable = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Paul')->setLastName('Sans-Email')
            ->setEmail('');
        $this->dossier->addPerson($person);
        $this->dossier->addPerson($second);
        $this->dossier->addPerson($unreachable);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = $this->values();
        // notifyClient est vrai par défaut : la modale propose l'envoi coché.
        self::assertTrue($component->notifyClient);
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        self::assertEmailCount(2);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame('jean@visit-form-test.local', $email->getTo()[0]->getAddress());
        // Langue du destinataire (fr par défaut) : objet et corps en français,
        // avec l'adresse et l'heure du créneau.
        self::assertStringContainsString('Votre visite', (string) $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $html);
        self::assertStringContainsString('30 min', $html);
    }

    public function testCreateSendsNoEmailWhenNotifyIsUnticked(): void
    {
        $person = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean@visit-form-test.local')
            ->setPrimaryContact(true);
        $this->dossier->addPerson($person);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->notifyClient = false;
        $component->formValues = $this->values();
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        self::assertEmailCount(0);
    }

    public function testCreateSurvivesAPrimaryContactWithoutEmail(): void
    {
        $person = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Martin')
            // Colonne NOT NULL : l'absence d'adresse se stocke en vide.
            ->setEmail('')
            ->setPrimaryContact(true);
        $this->dossier->addPerson($person);
        $this->em->flush();

        // Contact principal sans email : rien à envoyer, la visite se crée.
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));

        self::assertEmailCount(0);
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
    private function renderWithDossier(bool $contactsOpen = false, bool $propertyDetailsOpen = false, bool $photosOpen = false, bool $recapMoreOpen = false): string
    {
        $visit = (new Visit())->setDossier($this->dossier);

        return (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $visit,
            'contactsOpen' => $contactsOpen,
            'propertyDetailsOpen' => $propertyDetailsOpen,
            'photosOpen' => $photosOpen,
            'recapMoreOpen' => $recapMoreOpen,
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
