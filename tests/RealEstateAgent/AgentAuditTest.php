<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentDetails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Fiche agent : instantanés du créateur et du dernier modificateur
 * (nom + photo de profil), affichés dans la card de traçabilité en bas.
 */
final class AgentAuditTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';
    private const AVATAR = 'users/01HXAMPLEULID0000000000000/avatar/0198a5e0-0000-7000-8000-000000000042.webp';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-audit-test.local')->execute();
    }

    public function testSavingTheFicheSnapshotsTheEditorNameAndAvatar(): void
    {
        $this->loginAs('Sophie', 'Martin', self::AVATAR);
        $agent = $this->persistAgent();

        $component = $this->mountTwigComponent(AgentDetails::class, [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        $component->startEditing();
        $component->firstName = 'Jean';
        $component->lastName = 'Durand';
        $component->saveDetails($this->em, self::getContainer()->get(\Symfony\Contracts\Translation\TranslatorInterface::class));

        $this->em->clear();
        $fresh = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertSame('Sophie Martin', $fresh->getUpdatedByName());
        self::assertSame(self::AVATAR, $fresh->getUpdatedByAvatar());
        self::assertNotNull($fresh->getUpdatedAt());
    }

    public function testTheAuditCardShowsCreatorAndEditorWithTheirAvatar(): void
    {
        $this->loginAs('Sophie', 'Martin', self::AVATAR);
        $agent = $this->persistAgent()
            ->setCreatedByName('Paul Petit')
            ->setCreatedByAvatar(self::AVATAR)
            ->setUpdatedAt(new \DateTimeImmutable('2026-08-16'))
            ->setUpdatedByName('Sophie Martin')
            ->setUpdatedByAvatar(self::AVATAR);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-audit"', $rendered);
        self::assertStringContainsString('Paul Petit', $rendered);
        self::assertStringContainsString('data-testid="agent-audit-updated"', $rendered);
        self::assertStringContainsString('Sophie Martin', $rendered);
        // Les avatars des deux instantanés passent par le composant Avatar.
        self::assertGreaterThanOrEqual(2, substr_count($rendered, self::AVATAR));
    }

    public function testAFicheNeverEditedHidesTheUpdatedTile(): void
    {
        $this->loginAs('Sophie', 'Martin', null);
        $agent = $this->persistAgent();
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => (int) $agent->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-audit"', $rendered);
        self::assertStringNotContainsString('data-testid="agent-audit-updated"', $rendered);
    }

    private function persistAgent(): RealEstateAgent
    {
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')
            ->setLastName('Martin')
            ->setCreatedAt(new \DateTimeImmutable('2026-08-10'));
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAs(string $firstName, string $lastName, ?string $avatar): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agent-audit-test.local')
            ->setFirstName($firstName)->setLastName($lastName)
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true)
            ->setAvatarFilename($avatar);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
