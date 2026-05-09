<?php

namespace App\Tests\Admin\Components;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Component-level checks for the admin user list pagination:
 *  - render shows PER_PAGE rows + "load more" button when there are more
 *  - calling more() exposes the next batch
 *  - non-admin users get AccessDenied
 */
final class UserListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PER_PAGE = 20;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testRendersFirstPageAndLoadMoreWhenOverflow(): void
    {
        $this->seedAdmin('admin@example.com');
        for ($i = 0; $i < 25; ++$i) {
            $this->seedUser(sprintf('user%02d@example.com', $i), new \DateTimeImmutable('2026-04-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)));
        }
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $component = $this->mountTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertSame(26, $component->getTotalCount());
        self::assertCount(self::PER_PAGE, $component->getItems());
        self::assertTrue($component->hasMore());

        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringContainsString('data-testid="users-load-more"', $html);
        self::assertStringContainsString('admin@example.com', $html);
        // Each row exposes a profile link so the whole row is clickable.
        self::assertStringContainsString('data-testid="user-row-link"', $html);
        self::assertStringContainsString('test_admin_prefix_1234567890abcdef/admin/utilisateurs/', $html);
    }

    public function testMoreActionGrowsTheVisibleSet(): void
    {
        $this->seedAdmin('admin@example.com');
        for ($i = 0; $i < 25; ++$i) {
            $this->seedUser(sprintf('user%02d@example.com', $i), new \DateTimeImmutable('2026-04-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)));
        }
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $component = $this->mountTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $component->more();

        // 26 total; page 2 of 20 → 26 visible, no more.
        self::assertCount(26, $component->getItems());
        self::assertFalse($component->hasMore());
    }

    public function testHidesLoadMoreWhenAllUsersFit(): void
    {
        $this->seedAdmin('admin@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $this->seedUser(sprintf('user%02d@example.com', $i), new \DateTimeImmutable('2026-04-01'));
        }
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $component = $this->mountTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertFalse($component->hasMore());
        $html = (string) $this->renderTwigComponent('Admin:UserList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringNotContainsString('data-testid="users-load-more"', $html);
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
            ->setCreatedAt($createdAt);
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
