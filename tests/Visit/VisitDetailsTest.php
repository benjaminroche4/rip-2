<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\VisitType;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Inline editor of the visit detail card: padlock gating, chip autosave,
 * per-field validation (future date only when moved, Île-de-France
 * address), and the relock-drops-draft behaviour.
 */
final class VisitDetailsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-details-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testFieldsAreShieldedWhileLocked(): void
    {
        $visit = $this->persistVisit();
        $component = $this->mount($visit);

        // Locked by default: nothing mutates.
        $component->chooseType('inventory');
        $component->address = 'Autre adresse, 75002 Paris';
        $component->save();

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(VisitType::PropertyVisit, $reloaded->getType());
        self::assertSame('12 rue de la Roquette, 75011 Paris', $reloaded->getAddress());
    }

    public function testChipsAndFieldsAutosaveOnceUnlocked(): void
    {
        $visit = $this->persistVisit();
        $component = $this->mount($visit);
        $component->toggleLock();

        $component->chooseType('technical_intervention');
        $component->toggleClientPresent();
        $component->note = 'Code 4812, gardien au rez-de-chaussée';
        $component->durationMinutes = '60';
        $component->save();

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(VisitType::TechnicalIntervention, $reloaded->getType());
        self::assertFalse($reloaded->isClientPresent());
        self::assertSame('Code 4812, gardien au rez-de-chaussée', $reloaded->getNote());
        self::assertSame(60, $reloaded->getDurationMinutes());
    }

    public function testAddressOutsideIleDeFranceIsRejected(): void
    {
        $visit = $this->persistVisit();
        $component = $this->mount($visit);
        $component->toggleLock();

        $component->address = '12 rue de la République, 69001 Lyon';
        $component->save();

        self::assertArrayHasKey('address', $component->errors);
        $this->em->clear();
        self::assertSame('12 rue de la Roquette, 75011 Paris', $this->em->find(Visit::class, $visit->getId())->getAddress());
    }

    public function testUnchangedPastDateDoesNotBlockOtherEdits(): void
    {
        // Visite déjà passée : éditer la note ne doit pas buter sur la date.
        $visit = $this->persistVisit(new \DateTimeImmutable('-2 days 10:00'));
        $component = $this->mount($visit);
        $component->toggleLock();

        $component->note = 'Compte OK';
        $component->save();

        self::assertSame([], $component->errors);
        $this->em->clear();
        self::assertSame('Compte OK', $this->em->find(Visit::class, $visit->getId())->getNote());

        // Mais déplacer la date VERS le passé reste refusé.
        $component->scheduledAt = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i');
        $component->save();
        self::assertArrayHasKey('scheduledAt', $component->errors);
    }

    public function testRelockingDropsTheDraft(): void
    {
        $visit = $this->persistVisit();
        $component = $this->mount($visit);
        $component->toggleLock();
        $component->address = 'Brouillon non sauvé';

        $component->toggleLock();

        self::assertSame('12 rue de la Roquette, 75011 Paris', $component->address);
        self::assertSame([], $component->errors);
    }

    private function mount(Visit $visit): object
    {
        $component = $this->mountTwigComponent('Visit:VisitDetails', [
            'visitId' => (int) $visit->getId(),
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        $component->setLiveResponder(new LiveResponder());

        return $component;
    }

    private function persistVisit(?\DateTimeImmutable $scheduledAt = null): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt($scheduledAt ?? new \DateTimeImmutable('+2 days 10:30'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-details-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
