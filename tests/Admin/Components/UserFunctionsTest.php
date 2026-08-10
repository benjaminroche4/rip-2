<?php

namespace App\Tests\Admin\Components;

use App\Admin\Twig\Components\UserFunctions;
use App\Auth\Domain\StaffFunction;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:UserFunctions behaviour on the user profile page: one toggle per
 * business function, saved on click, admin-only.
 */
final class UserFunctionsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-functions-test.local')->execute();
    }

    public function testToggleFunctionGrantsAndRevokes(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-functions-test.local');

        $component = $this->mount($target);

        $component->toggleFunction('search_agent');
        $this->em->refresh($target);
        self::assertTrue($target->hasStaffFunction(StaffFunction::SearchAgent));
        self::assertTrue($component->hasFunction('search_agent'));

        $component->toggleFunction('closer');
        $this->em->refresh($target);
        self::assertTrue($target->hasStaffFunction(StaffFunction::Closer));
        self::assertTrue($target->hasStaffFunction(StaffFunction::SearchAgent), 'Adding a function keeps the others.');

        $component->toggleFunction('search_agent');
        $this->em->refresh($target);
        self::assertFalse($target->hasStaffFunction(StaffFunction::SearchAgent));
        self::assertTrue($target->hasStaffFunction(StaffFunction::Closer));
    }

    public function testUnknownFunctionIsRejected(): void
    {
        $this->loginAsAdmin();
        $component = $this->mount($this->seedUser('operator@user-functions-test.local'));

        $this->expectException(NotFoundHttpException::class);
        $component->toggleFunction('nonsense');
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $user = $this->seedUser('plain@user-functions-test.local');
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mount($user);
    }

    private function mount(User $target): UserFunctions
    {
        /** @var UserFunctions $component */
        $component = $this->mountTwigComponent('Admin:UserFunctions', ['userId' => (int) $target->getId()]);

        return $component;
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->seedUser('admin@user-functions-test.local', ['ROLE_ADMIN']);
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
