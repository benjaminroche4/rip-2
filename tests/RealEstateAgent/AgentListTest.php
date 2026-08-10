<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgentList behaviour: alphabetical directory order and the
 * free-text search over name, agency, email and phone.
 */
final class AgentListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-list-test.local')->execute();
    }

    public function testAgentsAreListedAlphabeticallyIgnoringCaseAndAccents(): void
    {
        $this->persistAgent('Zoé', 'Ébrard');
        $this->persistAgent('Anna', 'durand');
        $this->persistAgent('Paul', 'Astier');
        $this->loginAsAdmin();

        $rows = $this->mountList()->getAgents();

        self::assertSame(['Astier', 'durand', 'Ébrard'], array_column($rows, 'lastName'));
    }

    public function testSearchMatchesNameAgencyEmailAndPhone(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia Paris 11', email: 'jean@foncia.fr', phone: '+33611223344');
        $this->persistAgent('Paul', 'Durand', agency: 'Century 21', email: 'paul@century.fr', phone: '+33655667788');
        $this->loginAsAdmin();

        $component = $this->mountList();

        $component->search = 'martin';
        self::assertCount(1, $component->getAgents());

        $component->search = 'foncia';
        self::assertCount(1, $component->getAgents());

        $component->search = 'paul@century.fr';
        self::assertCount(1, $component->getAgents());

        $component->search = '+336556';
        self::assertCount(1, $component->getAgents());

        $component->search = 'introuvable';
        self::assertCount(0, $component->getAgents());

        // The total ignores the search: it describes the whole directory.
        self::assertSame(2, $component->getTotalCount());
    }

    public function testListRendersSearchAndRows(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia Paris 11', email: 'jean@foncia.fr', phone: '+33611223344');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="agents-search"', $rendered);
        self::assertStringContainsString('data-testid="agent-row"', $rendered);
        self::assertStringContainsString('data-testid="agent-email"', $rendered);
        self::assertStringContainsString('data-testid="agent-phone"', $rendered);
        self::assertStringContainsString('Jean Martin', $rendered);
        self::assertStringContainsString('Foncia Paris 11', $rendered);
    }

    public function testEmptyStateRendersWithoutAgents(): void
    {
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="agents-empty"', $rendered);
    }

    public function testMountIsDeniedWithoutTheAgentsSectionRole(): void
    {
        $staff = (new User())
            ->setEmail('staff@agent-list-test.local')
            ->setFirstName('Staff')->setLastName('Only')
            ->setRoles(['ROLE_STAFF'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($staff, 'main', $staff->getRoles()));

        $this->expectException(AccessDeniedException::class);
        $this->mountList();
    }

    private function mountList(): AgentList
    {
        /** @var AgentList $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function persistAgent(
        string $firstName,
        string $lastName,
        ?string $agency = null,
        ?string $email = null,
        ?string $phone = null,
    ): RealEstateAgent {
        $agencyEntity = null;
        if (null !== $agency) {
            $agencyEntity = $this->em->getRepository(Agency::class)->findOneBy(['name' => $agency])
                ?? (new Agency())->setName($agency)->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($agencyEntity);
        }

        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agencyEntity)
            ->setEmail($email)
            ->setPhone($phone)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@agent-list-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
