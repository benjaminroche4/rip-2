<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Entity\Visit;
use App\Visit\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Visits section on the agent detail page: the visits booked with this
 * directory agent, upcoming first then past, rows opening the visit page
 * only for staff with the Visits section access.
 */
final class AgentVisitsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-visits-test.local')->execute();
        $this->loginAs(['ROLE_ADMIN']);
    }

    public function testRepositoryReturnsOnlyTheAgentVisitsInChronologicalOrder(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $other = $this->persistAgent('Paul', 'Durand');
        $dossier = $this->persistDossier('Famille Martin');
        $this->persistVisit($dossier, $agent, '+3 days', '3 rue Future, Paris');
        $this->persistVisit($dossier, $agent, '-2 days', '2 rue Passee, Paris');
        $this->persistVisit($dossier, $other, '+1 day', '9 rue Autre, Paris');

        /** @var VisitRepository $visits */
        $visits = self::getContainer()->get(VisitRepository::class);
        $rows = $visits->findByRealEstateAgentSummaries((int) $agent->getId());

        self::assertSame(['2 rue Passee, Paris', '3 rue Future, Paris'], array_column($rows, 'address'));
    }

    public function testDetailPageListsUpcomingThenPastVisitsWithStatusAndLink(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $dossier = $this->persistDossier('Famille Martin');
        $this->persistVisit($dossier, $agent, '+3 days', '3 rue Future, Paris');
        $this->persistVisit($dossier, $agent, '-2 days', '2 rue Passee, Paris');

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-visits"', $rendered);
        self::assertSame(2, substr_count($rendered, 'data-testid="agent-visit-row"'));
        self::assertSame(2, substr_count($rendered, 'data-testid="agent-visit-status"'));
        // Upcoming group first, past group after; the future address renders
        // before the past one.
        self::assertStringContainsString('data-testid="agent-visits-group-upcoming"', $rendered);
        self::assertStringContainsString('data-testid="agent-visits-group-past"', $rendered);
        self::assertLessThan(strpos($rendered, '2 rue Passee, Paris'), strpos($rendered, '3 rue Future, Paris'));
        // With the Visits section access, rows link to the visit page.
        self::assertSame(2, substr_count($rendered, 'data-testid="agent-visit-link"'));
        self::assertStringContainsString('/'.self::PREFIX.'/admin/', $rendered);
    }

    public function testWithoutVisitsSectionAccessRowsAreNotClickable(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $dossier = $this->persistDossier('Famille Martin');
        $this->persistVisit($dossier, $agent, '+3 days', '3 rue Future, Paris');

        $this->loginAs(['ROLE_SECTION_AGENTS']);

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="agent-visit-row"'));
        self::assertStringNotContainsString('data-testid="agent-visit-link"', $rendered);
    }

    public function testEmptyStateShowsADiscreetLine(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-visits-empty"', $rendered);
        self::assertStringContainsString('Aucune visite pour le moment', $rendered);
        self::assertStringContainsString('apparaîtront ici', $rendered);
        self::assertStringNotContainsString('data-testid="agent-visits-counter"', $rendered);
    }

    private function persistAgent(string $firstName, string $lastName): RealEstateAgent
    {
        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function persistDossier(string $name): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function persistVisit(Dossier $dossier, RealEstateAgent $agent, string $scheduledAt, string $address): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setAgent($agent)
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt))
            ->setAddress($address)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agent-visits-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
