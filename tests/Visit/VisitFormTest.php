<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
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
 * Visit:VisitForm split-screen behaviour: creation (with preview or
 * fallback geocoding), the debounced locate action feeding the summary map,
 * the live summary labels, and validation failures.
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
            ->setCreatedAt(new \DateTimeImmutable());
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
        self::assertSame('2026-06-20 10:30', $visit->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertNull($visit->getLatitude(), 'No key, no preview: coordinates stay null.');
    }

    public function testSelectingAPlaceDropsThePinOnTheSummaryMap(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        // Fired by the places-autocomplete trigger after a suggestion pick.
        $component->setLocation(48.8553, 2.3765);

        self::assertSame(48.8553, $component->previewLat);
        self::assertSame(2.3765, $component->previewLng);
        self::assertSame('12 rue de la Roquette, 75011 Paris', $component->locatedAddress);
        self::assertNotNull($component->getPreviewMap());
    }

    public function testEditingTheAddressAfterASelectionHidesTheStalePin(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();
        $component->setLocation(48.8553, 2.3765);

        $component->formValues['address'] = '12 rue de la Roquette, 75011 Paris mais ailleurs';

        self::assertNull($component->getPreviewMap(), 'The pin only reflects a confirmed address.');
    }

    public function testCreateReusesThePreviewCoordinates(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values();

        $component->setLocation(48.8553, 2.3765);
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

    public function testSummaryLabelsFollowTheFormValues(): void
    {
        $agency = (new Agency())->setName('Foncia Paris 11')->setCreatedAt(new \DateTimeImmutable());
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')->setAgency($agency)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $this->em->persist($agent);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = $this->values(agent: (string) $agent->getId());

        self::assertSame('Famille Martin ('.$this->dossier->getReference().')', $component->getSummaryDossier());
        self::assertSame('Jean Martin (Foncia Paris 11)', $component->getSummaryAgent());
        self::assertSame('2026-06-20 10:30', $component->getSummaryScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame('12 rue de la Roquette, 75011 Paris', $component->getSummaryAddress());
    }

    public function testEmptyFormYieldsAnEmptySummary(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(dossier: '', agent: '', scheduledAt: '', address: '');

        self::assertNull($component->getSummaryDossier());
        self::assertNull($component->getSummaryAgent());
        self::assertNull($component->getSummaryScheduledAt());
        self::assertNull($component->getSummaryAddress());
        self::assertNull($component->getPreviewMap());
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

    public function testSplitScreenMarkupRendersFormAndSummary(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="visit-form"', $rendered);
        self::assertStringContainsString('data-testid="visit-summary"', $rendered);
        self::assertStringContainsString('data-testid="visit-summary-map-placeholder"', $rendered);
        // The address field carries the shared Places autocomplete, whose
        // selection trigger feeds the setLocation action.
        self::assertStringContainsString('data-controller="places-autocomplete"', $rendered);
        self::assertStringContainsString('data-places-autocomplete-target="results"', $rendered);
        self::assertStringContainsString('data-live-action-param="setLocation"', $rendered);
        // Anti double-submit guard on the create action.
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
    }

    /**
     * @return array<string, string>
     */
    private function values(
        ?string $dossier = null,
        string $agent = '',
        string $scheduledAt = '2026-06-20T10:30',
        string $address = '12 rue de la Roquette, 75011 Paris',
    ): array {
        return [
            'dossier' => $dossier ?? (string) $this->dossier->getId(),
            'agent' => $agent,
            'scheduledAt' => $scheduledAt,
            'address' => $address,
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
