<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:ManagerPicker behaviour: assign / change / unassign the
 * "responsable de dossier", choices restricted to admin & editor users.
 */
final class ManagerPickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@manager-picker-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testAssignsAndUnassignsAManager(): void
    {
        $dossier = $this->persistDossier();
        $editor = $this->persistUser('editor', ['ROLE_EDITOR']);
        $component = $this->mountPicker($dossier);

        self::assertNull($component->getManager());

        $component->chooseManager($editor->getId());
        self::assertSame($editor->getId(), $component->getManager()?->id);
        $this->em->clear();
        self::assertSame($editor->getId(), $this->em->find(Dossier::class, $dossier->getId())->getManager()?->getId());

        $component->removeManager();
        self::assertNull($component->getManager());
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getManager());
    }

    public function testChoicesOnlyContainAdminSpaceUsers(): void
    {
        $dossier = $this->persistDossier();
        $editor = $this->persistUser('editor', ['ROLE_EDITOR']);
        $plain = $this->persistUser('plain', []);
        $component = $this->mountPicker($dossier);

        $ids = array_map(fn ($choice) => $choice->id, $component->getChoices());

        self::assertContains($editor->getId(), $ids);
        self::assertNotContains($plain->getId(), $ids);
    }

    public function testCannotAssignARegularUser(): void
    {
        $dossier = $this->persistDossier();
        $plain = $this->persistUser('plain', []);
        $component = $this->mountPicker($dossier);

        $this->expectException(NotFoundHttpException::class);
        $component->chooseManager($plain->getId());
    }

    private function persistDossier(): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@example.com')
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-000042')
            ->setPairingCode('ABE78L')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $slug, array $roles): User
    {
        $user = (new User())
            ->setEmail($slug.'-'.bin2hex(random_bytes(3)).'@manager-picker-test.local')
            ->setFirstName(ucfirst($slug))->setLastName('Staff')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function mountPicker(Dossier $dossier): object
    {
        return $this->mountTwigComponent('Dossier:ManagerPicker', ['dossierId' => $dossier->getId()]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin-'.bin2hex(random_bytes(3)).'@manager-picker-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
