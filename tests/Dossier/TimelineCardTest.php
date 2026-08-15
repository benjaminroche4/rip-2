<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:Timeline empty state: a fresh dossier without a move-in date shows
 * a locked card inviting to fill the search, never a silent void.
 */
final class TimelineCardTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.name LIKE :p')->setParameter('p', 'Timeline%')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@timeline-test.local')->execute();
    }

    public function testFreshDossierShowsTheLockedCardInsteadOfNothing(): void
    {
        $dossier = (new Dossier())
            ->setName('Timeline Famille Test')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->flush();
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('Dossier:Timeline', ['dossierId' => (int) $dossier->getId()]);

        self::assertStringNotContainsString('data-testid="dossier-show-timeline"', $rendered);
        self::assertStringContainsString('data-testid="dossier-timeline-locked"', $rendered);
        self::assertStringContainsString('Renseignez la date d', $rendered);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@timeline-test.local')
            ->setFirstName('Admin')
            ->setLastName('Timeline')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $admin->setPassword('irrelevant');
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
