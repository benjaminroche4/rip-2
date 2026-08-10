<?php

namespace App\Tests\Admin\Components;

use App\Admin\Twig\Components\UserDanger;
use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:UserDanger behaviour on the user profile page: account deletion
 * behind a confirmation modal, with the self-deletion guard and the
 * reset-password requests purge.
 */
final class UserDangerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-danger-test.local')->execute();
    }

    public function testDeleteGoesThroughTheConfirmationModal(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-danger-test.local');

        $component = $this->mount($target);

        // Asking opens the modal, nothing is deleted yet.
        $component->askDelete();
        self::assertTrue($component->confirmingDelete);
        self::assertNotNull($this->em->find(User::class, $target->getId()));

        // Cancelling closes without deleting.
        $component->cancelDelete();
        self::assertFalse($component->confirmingDelete);
        self::assertNotNull($this->em->find(User::class, $target->getId()));
    }

    public function testConfirmDeleteRemovesTheUserAndItsResetRequestsThenRedirects(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-danger-test.local');
        $targetId = (int) $target->getId();

        $this->em->persist(new ResetPasswordRequest($target, new \DateTimeImmutable('+1 hour'), 'selector', 'hashed'));
        $this->em->flush();

        $component = $this->mount($target);
        $component->askDelete();
        $response = $component->confirmDelete();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/admin/utilisateurs', $response->getTargetUrl());

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $targetId));
        self::assertSame(
            0,
            (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.ResetPasswordRequest::class.' r')->getSingleScalarResult(),
        );
    }

    public function testAdminToggleGoesThroughTheConfirmationModal(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-danger-test.local');

        $component = $this->mount($target);

        // Asking opens the modal, nothing changes yet.
        $component->askToggleAdmin();
        self::assertTrue($component->confirmingAdmin);
        $this->em->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());

        // Cancelling closes without granting.
        $component->cancelToggleAdmin();
        self::assertFalse($component->confirmingAdmin);

        // Confirming grants; a second pass revokes.
        $component->askToggleAdmin();
        $component->confirmToggleAdmin();
        $this->em->refresh($target);
        self::assertContains('ROLE_ADMIN', $target->getRoles());

        $component->askToggleAdmin();
        $component->confirmToggleAdmin();
        $this->em->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
    }

    public function testAdminCannotToggleTheirOwnAdminRole(): void
    {
        $admin = $this->loginAsAdmin();
        $component = $this->mount($admin);

        $this->expectException(AccessDeniedException::class);
        $component->askToggleAdmin();
    }

    public function testSuspendAndResumeBothGoThroughTheirModal(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-danger-test.local');

        $component = $this->mount($target);

        // Asking opens the modal, nothing changes yet.
        $component->askSuspend();
        self::assertTrue($component->confirmingSuspend);
        $this->em->refresh($target);
        self::assertFalse($target->isSuspended());

        // Cancelling closes without suspending.
        $component->cancelSuspend();
        self::assertFalse($component->confirmingSuspend);

        // Confirming suspends.
        $component->askSuspend();
        $component->confirmSuspend();
        $this->em->refresh($target);
        self::assertTrue($target->isSuspended());

        // Resuming is confirmed too, and cancellable.
        $component->askResume();
        self::assertTrue($component->confirmingResume);
        $component->cancelResume();
        $this->em->refresh($target);
        self::assertTrue($target->isSuspended());

        $component->askResume();
        $component->confirmResume();
        $this->em->refresh($target);
        self::assertFalse($target->isSuspended());
    }

    public function testSuspendedBannerRendersOnlyWhileSuspended(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-danger-test.local');

        $html = (string) $this->renderTwigComponent('Admin:UserSuspendedBanner', ['userId' => (int) $target->getId()]);
        self::assertStringNotContainsString('role="alert"', $html);

        $target->setSuspended(true);
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:UserSuspendedBanner', ['userId' => (int) $target->getId()]);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('suspendu', $html);
    }

    public function testAdminCannotSuspendTheirOwnAccount(): void
    {
        $admin = $this->loginAsAdmin();
        $component = $this->mount($admin);

        $this->expectException(AccessDeniedException::class);
        $component->askSuspend();
    }

    public function testAdminCannotDeleteTheirOwnAccount(): void
    {
        $admin = $this->loginAsAdmin();
        $component = $this->mount($admin);

        $this->expectException(AccessDeniedException::class);
        $component->askDelete();
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $user = $this->seedUser('plain@user-danger-test.local');
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mount($user);
    }

    private function mount(User $target): UserDanger
    {
        /** @var UserDanger $component */
        $component = $this->mountTwigComponent('Admin:UserDanger', [
            'userId' => (int) $target->getId(),
            'adminPrefix' => (string) self::getContainer()->getParameter('admin_path_prefix'),
        ]);

        return $component;
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->seedUser('admin@user-danger-test.local', ['ROLE_ADMIN']);
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
