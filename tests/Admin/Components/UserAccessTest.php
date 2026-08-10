<?php

namespace App\Tests\Admin\Components;

use App\Admin\Twig\Components\UserAccess;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:UserAccess behaviour on the user profile page: per-section toggles
 * saved on click. The admin switch and its confirmation modal moved to
 * Admin:UserDanger (covered by UserDangerTest).
 */
final class UserAccessTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-access-test.local')->execute();
    }

    public function testToggleSectionGrantsAndRevokesTheAccess(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-access-test.local');

        $component = $this->mount($target);

        $component->toggleSection('dossiers');
        $this->em->refresh($target);
        self::assertContains('ROLE_SECTION_DOSSIERS', $target->getRoles());
        self::assertTrue($component->hasSection('dossiers'));

        $component->toggleSection('dossiers');
        $this->em->refresh($target);
        self::assertNotContains('ROLE_SECTION_DOSSIERS', $target->getRoles());
    }

    public function testUnknownSectionIsRejected(): void
    {
        $this->loginAsAdmin();
        $component = $this->mount($this->seedUser('operator@user-access-test.local'));

        $this->expectException(NotFoundHttpException::class);
        $component->toggleSection('nonsense');
    }

    public function testGrantBackOfficeGoesThroughTheModalAndStoresRoleStaff(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-access-test.local');

        $component = $this->mount($target);
        self::assertFalse($component->hasBackOfficeAccess());

        // Asking opens the modal, nothing changes yet.
        $component->askGrantBackOffice();
        self::assertTrue($component->confirmingGrant);
        $this->em->refresh($target);
        self::assertNotContains('ROLE_STAFF', $target->getRoles());

        // Cancelling closes without granting.
        $component->cancelGrantBackOffice();
        self::assertFalse($component->confirmingGrant);

        // Confirming stores the staff grade.
        $component->askGrantBackOffice();
        $component->confirmGrantBackOffice();
        $this->em->refresh($target);
        self::assertContains('ROLE_STAFF', $target->getRoles());
        self::assertTrue($component->hasBackOfficeAccess());
    }

    public function testRevokeBackOfficeGoesThroughTheModalAndStripsEverything(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-access-test.local', ['ROLE_STAFF', 'ROLE_SECTION_DOSSIERS', 'ROLE_SECTION_TOOLS']);

        $component = $this->mount($target);

        // Asking opens the modal, nothing changes yet.
        $component->askRevokeBackOffice();
        self::assertTrue($component->confirmingRevoke);
        $this->em->refresh($target);
        self::assertContains('ROLE_STAFF', $target->getRoles());

        // Cancelling closes without revoking.
        $component->cancelRevokeBackOffice();
        self::assertFalse($component->confirmingRevoke);

        // Confirming strips staff and every section at once.
        $component->askRevokeBackOffice();
        $component->confirmRevokeBackOffice();
        $this->em->refresh($target);
        self::assertSame(['ROLE_USER'], $target->getRoles());
        self::assertFalse($component->hasBackOfficeAccess());
    }

    public function testMasterToggleIsLockedForAnAdminTarget(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('other-admin@user-access-test.local', ['ROLE_ADMIN']);

        $component = $this->mount($target);
        self::assertTrue($component->hasBackOfficeAccess());

        $this->expectException(AccessDeniedException::class);
        $component->askRevokeBackOffice();
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $user = $this->seedUser('plain@user-access-test.local');
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mount($user);
    }

    private function mount(User $target): UserAccess
    {
        /** @var UserAccess $component */
        $component = $this->mountTwigComponent('Admin:UserAccess', ['userId' => (int) $target->getId()]);

        return $component;
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->seedUser('admin@user-access-test.local', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
