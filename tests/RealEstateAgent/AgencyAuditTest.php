<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgencyDetails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Fiche agent : instantanés du créateur et du dernier modificateur
 * (nom + photo de profil), affichés dans la card de traçabilité en bas.
 */
final class AgencyAuditTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-audit-test.local')->execute();
    }

    public function testSavingTheFicheSnapshotsTheEditorNameAndAvatar(): void
    {
        $this->loginAs('Sophie', 'Martin', self::AVATAR);
        $agency = $this->persistAgency();

        $component = $this->mountTwigComponent(AgencyDetails::class, [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
        $component->startEditing();
        $component->name = 'Foncia Bastille';
        $component->saveDetails($this->em, self::getContainer()->get(\Symfony\Contracts\Translation\TranslatorInterface::class));

        $this->em->clear();
        $fresh = $this->em->find(Agency::class, $agency->getId());
        self::assertSame('Sophie Martin', $fresh->getUpdatedByName());
        self::assertSame(self::AVATAR, $fresh->getUpdatedByAvatar());
        self::assertNotNull($fresh->getUpdatedAt());
    }

    public function testTheAuditCardShowsCreatorAndEditorWithTheirAvatar(): void
    {
        $this->loginAs('Sophie', 'Martin', self::AVATAR);
        $agency = $this->persistAgency()
            ->setCreatedByName('Paul Petit')
            ->setCreatedByAvatar(self::AVATAR)
            ->setUpdatedAt(new \DateTimeImmutable('2026-08-16'))
            ->setUpdatedByName('Sophie Martin')
            ->setUpdatedByAvatar(self::AVATAR);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agency-audit"', $rendered);
        self::assertStringContainsString('Paul Petit', $rendered);
        self::assertStringContainsString('data-testid="agency-audit-updated"', $rendered);
        self::assertStringContainsString('Sophie Martin', $rendered);
        self::assertGreaterThanOrEqual(2, substr_count($rendered, self::AVATAR));
    }

    public function testAFicheNeverEditedHidesTheUpdatedTile(): void
    {
        $this->loginAs('Sophie', 'Martin', null);
        $agency = $this->persistAgency();
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => (int) $agency->getId(),
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agency-audit"', $rendered);
        self::assertStringNotContainsString('data-testid="agency-audit-updated"', $rendered);
    }

    private function persistAgency(): Agency
    {
        $agency = (new Agency())
            ->setName('Foncia Paris 11')
            ->setCreatedAt(new \DateTimeImmutable('2026-08-10'));
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    private function loginAs(string $firstName, string $lastName, ?string $avatar): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agency-audit-test.local')
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
