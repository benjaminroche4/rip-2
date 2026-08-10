<?php

namespace App\Tests\Admin\Components;

use App\Admin\Twig\Components\UserIdentity;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:UserIdentity behaviour on the user profile page: edit mode with
 * explicit save/cancel (ContactDetails pattern), avatar reset, admin-only.
 */
final class UserIdentityTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-identity-test.local')->execute();
    }

    public function testSaveIdentityPersistsTrimmedNamesAndLeavesEditMode(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-identity-test.local');

        $component = $this->mount($target);
        $component->startEditing();
        self::assertTrue($component->editing);

        $component->firstName = '  Julien ';
        $component->lastName = ' Moreau  ';
        $component->saveIdentity();

        self::assertFalse($component->editing);
        $this->em->refresh($target);
        self::assertSame('Julien', $target->getFirstName());
        self::assertSame('Moreau', $target->getLastName());
    }

    public function testCancelEditingRestoresTheStoredValues(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-identity-test.local');

        $component = $this->mount($target);
        $component->startEditing();
        $component->firstName = 'Changed';
        $component->cancelEditing();

        self::assertFalse($component->editing);
        self::assertSame('First', $component->firstName);
        $this->em->refresh($target);
        self::assertSame('First', $target->getFirstName());
    }

    public function testResetAvatarClearsTheStoredFilename(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-identity-test.local');
        $target->setAvatarFilename('does-not-exist.webp');
        $this->em->flush();

        $component = $this->mount($target);
        $component->resetAvatar();

        $this->em->refresh($target);
        self::assertNull($target->getAvatarFilename());
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $user = $this->seedUser('plain@user-identity-test.local');
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mount($user);
    }

    private function mount(User $target): UserIdentity
    {
        /** @var UserIdentity $component */
        $component = $this->mountTwigComponent('Admin:UserIdentity', ['userId' => (int) $target->getId()]);

        return $component;
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->seedUser('admin@user-identity-test.local', ['ROLE_ADMIN']);
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
