<?php

namespace App\Tests\Admin\Components;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Component-level checks for the admin user list: every user is rendered
 * (no pagination), rows link to the profile, non-admin users get
 * AccessDenied.
 */
final class UserListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testRendersEveryUserWithoutPagination(): void
    {
        $this->seedAdmin('admin@example.com');
        for ($i = 0; $i < 25; ++$i) {
            $this->seedUser(sprintf('user%02d@example.com', $i), new \DateTimeImmutable('2026-04-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)));
        }
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $component = $this->mountTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertCount(26, $component->getItems());

        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringNotContainsString('data-testid="users-load-more"', $html);
        self::assertStringContainsString('Site web', $html, 'Password accounts read as connected via the website.');
        // Each row exposes a profile link so the whole row is clickable.
        self::assertStringContainsString('data-testid="user-row-link"', $html);
        self::assertStringContainsString('test_admin_prefix_1234567890abcdef/admin/utilisateurs/', $html);
    }

    public function testTwoFactorStatusIsVisiblePerRow(): void
    {
        $this->seedAdmin('admin@example.com');
        $protected = $this->seedUser('protected@example.com', new \DateTimeImmutable(), ['ROLE_STAFF']);
        $protected->setPlainTotpSecret('SECRETSECRETSECRET');
        $this->seedUser('unprotected@example.com', new \DateTimeImmutable(), ['ROLE_STAFF']);
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('Double auth', $html, 'The list has a dedicated 2FA column.');
        // One enabled chip (the protected account), disabled everywhere else.
        self::assertSame(1, substr_count($html, 'Activée'));
        self::assertStringContainsString('Désactivée', $html);
    }

    public function testRowsAreGroupedByGradeAndEmptyGroupsAreDropped(): void
    {
        $this->seedAdmin('admin@example.com');
        $this->seedUser('staff@example.com', new \DateTimeImmutable(), ['ROLE_SECTION_DOSSIERS']);
        $this->seedUser('user@example.com', new \DateTimeImmutable());
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('data-testid="users-group-admin"', $html);
        self::assertStringContainsString('data-testid="users-group-staff"', $html);
        self::assertStringContainsString('data-testid="users-group-user"', $html);
        // Groups keep the fixed order: admins, then staff, then plain users.
        self::assertLessThan(strpos($html, 'users-group-staff'), strpos($html, 'users-group-admin'));
        self::assertLessThan(strpos($html, 'users-group-user'), strpos($html, 'users-group-staff'));
    }

    public function testEmptyGradeGroupIsNotRendered(): void
    {
        $this->seedAdmin('admin@example.com');
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('data-testid="users-group-admin"', $html);
        self::assertStringNotContainsString('data-testid="users-group-staff"', $html);
        self::assertStringNotContainsString('data-testid="users-group-user"', $html);
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $this->seedUser('user@example.com', new \DateTimeImmutable());
        $this->em->flush();

        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
    }

    private function seedAdmin(string $email): User
    {
        return $this->seedUser($email, new \DateTimeImmutable(), ['ROLE_ADMIN']);
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, \DateTimeImmutable $createdAt, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt($createdAt)
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
