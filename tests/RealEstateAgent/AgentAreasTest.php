<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentCreate;
use App\RealEstateAgent\Twig\Components\AgentDetails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Adresse et quartiers favoris d'un agent indépendant : persistés à la
 * création et à l'édition (codes whitelistés), remis à null dès que l'agent
 * est rattaché à une agence, et affichés sur la fiche uniquement pour un
 * indépendant.
 */
final class AgentAreasTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-areas-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testIndependentCreatePersistsAddressAndWhitelistedAreas(): void
    {
        $component = $this->mountCreate();
        // Sélection Places : coordonnées immédiates, pas de géocodage serveur.
        $component->chooseAddressLocation(48.8631, 2.3708);
        // Un code inconnu glissé dans le CSV est filtré côté serveur.
        $component->areas = '11e,12e,zz,94';
        $component->formValues = $this->createValues([
            'kind' => 'independent',
            'address' => '12 rue de la Roquette, 75011 Paris',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getAgency());
        self::assertSame('12 rue de la Roquette, 75011 Paris', $agent->getAddress());
        self::assertSame('11e,12e,94', $agent->getAreas());
        self::assertSame(48.8631, $agent->getLatitude());
        self::assertSame(2.3708, $agent->getLongitude());
    }

    public function testAgencyKindDropsLeftoverAddressAndAreas(): void
    {
        $agency = (new Agency())->setName('Foncia Paris 11')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $this->em->flush();

        $component = $this->mountCreate();
        $component->agencyId = (int) $agency->getId();
        // Reliquats saisis avant de basculer le chip vers "En agence".
        $component->chooseAddressLocation(48.8631, 2.3708);
        $component->areas = '11e,12e';
        $component->formValues = $this->createValues([
            'kind' => 'agency',
            'address' => '12 rue de la Roquette, 75011 Paris',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertSame('Foncia Paris 11', $agent->getAgency()?->getName());
        self::assertNull($agent->getAddress());
        self::assertNull($agent->getAreas());
        self::assertNull($agent->getLatitude());
        self::assertNull($agent->getLongitude());
    }

    public function testDetailsIndependentSavesAddressAndWhitelistedAreas(): void
    {
        $agent = $this->persistIndependentAgent();

        $component = $this->mountDetails((int) $agent->getId());
        $component->startEditing();
        $component->address = '4 avenue Daumesnil, 75012 Paris';
        $component->chooseAddressLocation(48.8467, 2.3766);
        $component->areas = '12e,nope,93';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertFalse($component->editing);
        $this->em->clear();
        $saved = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertSame('4 avenue Daumesnil, 75012 Paris', $saved->getAddress());
        self::assertSame('12e,93', $saved->getAreas());
        self::assertSame(48.8467, $saved->getLatitude());
        self::assertSame(2.3766, $saved->getLongitude());
    }

    public function testDetailsSwitchToAgencyResetsAddressAreasAndCoordinates(): void
    {
        $agent = $this->persistIndependentAgent(
            address: '12 rue de la Roquette, 75011 Paris',
            areas: '11e,12e',
            latitude: 48.8631,
            longitude: 2.3708,
        );

        $component = $this->mountDetails((int) $agent->getId());
        $component->startEditing();
        // Champs préremplis depuis la fiche avant la bascule de mode.
        self::assertSame('12 rue de la Roquette, 75011 Paris', $component->address);
        self::assertSame('11e,12e', $component->areas);
        $century = (new Agency())->setName('Century 21 Bastille')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($century);
        $this->em->flush();
        $component->chooseAgency((int) $century->getId());
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        $this->em->clear();
        $saved = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertSame('Century 21 Bastille', $saved->getAgency()?->getName());
        self::assertNull($saved->getAddress());
        self::assertNull($saved->getAreas());
        self::assertNull($saved->getLatitude());
        self::assertNull($saved->getLongitude());
    }

    public function testShowRendersAddressAndAreasForAnIndependentAgent(): void
    {
        $agent = $this->persistIndependentAgent(
            address: '12 rue de la Roquette, 75011 Paris',
            areas: '11e,94',
            latitude: 48.8631,
            longitude: 2.3708,
        );

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-show-address"', $rendered);
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $rendered);
        self::assertStringContainsString('data-testid="agent-show-areas"', $rendered);
        // Chips des quartiers, mêmes libellés que la fiche agence.
        self::assertStringContainsString('Val-de-Marne (94)', $rendered);
        self::assertStringContainsString('data-testid="agent-address-map"', $rendered);
    }

    public function testShowHidesAddressAndAreasForAnAgencyAgent(): void
    {
        $agency = (new Agency())->setName('Foncia Paris 11')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setAgency($agency)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringNotContainsString('data-testid="agent-show-address"', $rendered);
        self::assertStringNotContainsString('data-testid="agent-show-areas"', $rendered);
        self::assertStringNotContainsString('data-testid="agent-address-map"', $rendered);
    }

    public function testCreateFormHidesAddressAndAreasForTheDefaultAgencyKind(): void
    {
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentCreate', [
            'adminPrefix' => self::PREFIX,
        ]);

        // Le mode par défaut est "En agence" : adresse et quartiers favoris
        // restent masqués (l'adresse est celle de l'agence).
        self::assertStringNotContainsString('data-testid="agent-create-address-block"', $rendered);
        self::assertStringNotContainsString('data-testid="agent-create-areas-block"', $rendered);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createValues(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'position' => '',
            'specialties' => [],
            'professionalCards' => [],
            'email' => '',
            'phone' => '',
            'address' => '',
            'note' => '',
        ], $overrides);
    }

    private function createAction(AgentCreate $component): ?RedirectResponse
    {
        return $component->create($this->em, self::getContainer()->get(TranslatorInterface::class));
    }

    private function mountCreate(): AgentCreate
    {
        /** @var AgentCreate $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentCreate', ['adminPrefix' => self::PREFIX]);

        return $component;
    }

    private function mountDetails(int $agentId): AgentDetails
    {
        /** @var AgentDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => $agentId,
            'adminPrefix' => self::PREFIX,
        ]);

        return $component;
    }

    private function persistIndependentAgent(
        ?string $address = null,
        ?string $areas = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ): RealEstateAgent {
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')
            ->setLastName('Martin')
            ->setAddress($address)
            ->setAreas($areas)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agent-areas-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
