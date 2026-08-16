<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use App\RealEstateAgent\Twig\Components\AgencyDetails;
use App\RealEstateAgent\Twig\Components\AgentDetails;
use App\Visit\Form\VisitType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Deactivation of agencies and agents, driven from the detail pages: the
 * state switch with its modal confirmation, the inactive badges and banner,
 * and the picker exclusions (datalist, visit dropdowns) while the directory
 * keeps every entry.
 */
final class DeactivationTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@deactivation-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testAgencyDeactivationIsConfirmedInAModalAndKeepsItsAgents(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $agent = $this->persistAgent('Jean', 'Martin', $agency);

        /** @var AgencyDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        $component->askDeactivate();
        self::assertTrue($component->confirmingDeactivate);

        $component->deactivateAgency($this->em, self::getContainer()->get(TranslatorInterface::class));
        self::assertFalse($component->confirmingDeactivate);

        $this->em->clear();
        $saved = $this->em->find(Agency::class, $agency->getId());
        self::assertNotNull($saved->getDeactivatedAt());
        self::assertFalse($saved->isActive());
        // Its agents and their attachment are untouched.
        $savedAgent = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertSame($agency->getId(), $savedAgent->getAgency()?->getId());
    }

    public function testDeactivatedAgencyShowsItsBadgeBannerAndOffSwitch(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11', deactivated: true);

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agency-show-inactive"', $rendered);
        self::assertStringContainsString('data-testid="agency-deactivated-banner"', $rendered);
        // The state switch reads inactive; flipping it back is direct, no modal.
        self::assertMatchesRegularExpression('~aria-checked="false"[^>]*data-testid="agency-active-switch"~s', $rendered);
        self::assertStringContainsString('reactivateAgency', $rendered);
    }

    public function testActiveAgencyShowsTheOnSwitchAndTheConfirmModalWhenAsked(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        self::assertMatchesRegularExpression('~aria-checked="true"[^>]*data-testid="agency-active-switch"~s', $rendered);
        self::assertStringNotContainsString('data-testid="agency-deactivated-banner"', $rendered);
        self::assertStringNotContainsString('data-testid="agency-deactivate-modal"', $rendered);

        $confirming = (string) $this->renderTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
            'confirmingDeactivate' => true,
        ]);
        self::assertStringContainsString('data-testid="agency-deactivate-modal"', $confirming);
        self::assertStringContainsString('data-testid="agency-deactivate-confirm"', $confirming);
    }

    public function testReactivatingAnAgencyClearsTheDeactivation(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11', deactivated: true);

        /** @var AgencyDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        $component->reactivateAgency($this->em, self::getContainer()->get(TranslatorInterface::class));

        $this->em->clear();
        self::assertTrue($this->em->find(Agency::class, $agency->getId())->isActive());
    }

    public function testDeactivatedAgencyLeavesTheAgentFormDatalist(): void
    {
        $this->persistAgency('Foncia Paris 11', deactivated: true);
        $this->persistAgency('Century 21 Bastille');

        /** @var AgencyRepository $agencies */
        $agencies = self::getContainer()->get(AgencyRepository::class);
        self::assertSame(['Century 21 Bastille'], $agencies->findAllNames());
    }

    public function testFindOrCreateStillMatchesADeactivatedAgencyWithoutReactivatingIt(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11', deactivated: true);

        /** @var AgencyRepository $agencies */
        $agencies = self::getContainer()->get(AgencyRepository::class);
        $matched = $agencies->findOrCreate('foncia paris 11');

        // Same row (no duplicate), still deactivated.
        self::assertSame($agency->getId(), $matched->getId());
        self::assertFalse($matched->isActive());
        self::assertSame(1, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testAgentDeactivationIsConfirmedInAModalFromTheDetailPage(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        /** @var AgentDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        $component->askDeactivate();
        self::assertTrue($component->confirmingDeactivate);

        $component->deactivateAgent($this->em);
        self::assertFalse($component->confirmingDeactivate);

        $this->em->clear();
        self::assertFalse($this->em->find(RealEstateAgent::class, $agent->getId())->isActive());
    }

    public function testDeactivatedAgentShowsItsBadgeBannerAndOffSwitchOnTheDetailPage(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin', deactivated: true);

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-show-inactive"', $rendered);
        self::assertStringContainsString('data-testid="agent-deactivated-banner"', $rendered);
        self::assertMatchesRegularExpression('~aria-checked="false"[^>]*data-testid="agent-active-switch"~s', $rendered);
        // Flipping the switch back on is a direct action, no modal.
        self::assertStringContainsString('reactivateAgent', $rendered);
    }

    public function testActiveAgentShowsTheOnSwitchAndTheConfirmModalWhenAsked(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        self::assertMatchesRegularExpression('~aria-checked="true"[^>]*data-testid="agent-active-switch"~s', $rendered);
        self::assertStringNotContainsString('data-testid="agent-deactivated-banner"', $rendered);
        self::assertStringNotContainsString('data-testid="agent-deactivate-modal"', $rendered);

        $confirming = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
            'confirmingDeactivate' => true,
        ]);
        self::assertStringContainsString('data-testid="agent-deactivate-modal"', $confirming);
        self::assertStringContainsString('data-testid="agent-deactivate-confirm"', $confirming);
    }

    public function testDirectoryShowsTheInactiveBadgeButCarriesNoDeactivationAction(): void
    {
        $this->persistAgent('Jean', 'Martin', deactivated: true);

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => self::PREFIX,
        ]);

        // The listing keeps the entry, greyed out and badged, but the
        // deactivation lives on the detail page only.
        self::assertStringContainsString('data-testid="agent-inactive"', $rendered);
        self::assertStringContainsString('Inactif', $rendered);
        self::assertStringNotContainsString('askDeactivate', $rendered);
        self::assertStringNotContainsString('reactivateAgent', $rendered);
    }

    public function testReactivatingAnAgentClearsTheDeactivation(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin', deactivated: true);

        /** @var AgentDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        $component->reactivateAgent($this->em);

        $this->em->clear();
        self::assertTrue($this->em->find(RealEstateAgent::class, $agent->getId())->isActive());
    }

    public function testDeactivationActionsRequireTheAgentsSectionRole(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        /** @var AgentDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        // Same session, role revoked: the live action must be refused.
        $staff = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@deactivation-test.local')
            ->setFirstName('Staff')->setLastName('Only')
            ->setRoles(['ROLE_STAFF'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($staff, 'main', $staff->getRoles()));

        $this->expectException(AccessDeniedException::class);
        $component->askDeactivate();
    }

    public function testDeactivatedAgentLeavesTheVisitAgentPickers(): void
    {
        $active = $this->persistAgent('Jean', 'Martin');
        $this->persistAgent('Paul', 'Durand', deactivated: true);

        /** @var RealEstateAgentRepository $agents */
        $agents = self::getContainer()->get(RealEstateAgentRepository::class);
        $ids = array_map(static fn (RealEstateAgent $a): ?int => $a->getId(), $agents->findActiveOrdered());
        self::assertSame([$active->getId()], $ids);

        // The visit form dropdown relies on the same exclusion.
        $form = self::getContainer()->get('form.factory')->create(VisitType::class);
        $choices = $form->get('agent')->createView()->vars['choices'];
        self::assertCount(1, $choices);
        self::assertStringContainsString('Jean Martin', (string) $choices[array_key_first($choices)]->label);
    }

    private function persistAgency(string $name, bool $deactivated = false): Agency
    {
        $agency = (new Agency())
            ->setName($name)
            ->setCreatedAt(new \DateTimeImmutable());
        if ($deactivated) {
            $agency->setDeactivatedAt(new \DateTimeImmutable());
        }
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    private function persistAgent(string $firstName, string $lastName, ?Agency $agency = null, bool $deactivated = false): RealEstateAgent
    {
        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agency)
            ->setCreatedAt(new \DateTimeImmutable());
        if ($deactivated) {
            $agent->setDeactivatedAt(new \DateTimeImmutable());
        }
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@deactivation-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
