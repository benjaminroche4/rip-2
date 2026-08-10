<?php

namespace App\Tests\Admin\Repository;

use App\Admin\Repository\AdminUserRepository;
use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Hits a real DB through the kernel. Verifies that listAll() returns DTOs,
 * orders rows by createdAt DESC, and derives the primary role correctly.
 */
final class AdminUserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AdminUserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->repo = $container->get(AdminUserRepository::class);

        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testListAllReturnsDtosOrderedByCreatedAtDesc(): void
    {
        $older = $this->persistUser('older@example.com', 'Older', 'User', new \DateTimeImmutable('2026-01-01'));
        $newer = $this->persistUser('newer@example.com', 'Newer', 'User', new \DateTimeImmutable('2026-04-01'));
        $this->em->flush();

        $items = $this->repo->listAll();

        self::assertCount(2, $items);
        self::assertSame('newer@example.com', $items[0]->email);
        self::assertSame('older@example.com', $items[1]->email);
        self::assertSame('Newer User', $items[0]->fullName());
        // lastLoginAt is null until the user actually logs in.
        self::assertNull($items[0]->lastLoginAt);
        self::assertNull($items[1]->lastLoginAt);
    }

    public function testListAllOrdersByGradeThenNewest(): void
    {
        $this->persistUser('old-user@example.com', 'Old', 'User', new \DateTimeImmutable('2026-01-01'));
        $this->persistUser('new-user@example.com', 'New', 'User', new \DateTimeImmutable('2026-05-01'));
        $this->persistUser('staff@example.com', 'Some', 'Staff', new \DateTimeImmutable('2026-03-01'), ['ROLE_SECTION_DOSSIERS']);
        $this->persistUser('old-admin@example.com', 'Old', 'Admin', new \DateTimeImmutable('2026-02-01'), ['ROLE_ADMIN']);
        $this->persistUser('new-admin@example.com', 'New', 'Admin', new \DateTimeImmutable('2026-04-01'), ['ROLE_ADMIN']);
        $this->em->flush();

        $emails = array_map(static fn ($item) => $item->email, $this->repo->listAll());

        self::assertSame([
            'new-admin@example.com',
            'old-admin@example.com',
            'staff@example.com',
            'new-user@example.com',
            'old-user@example.com',
        ], $emails);
    }

    public function testLastLoginAtIsExposedAsImmutable(): void
    {
        $stamp = new \DateTimeImmutable('2026-04-15 10:30:00');
        $user = $this->persistUser('seen@example.com', 'Seen', 'User', new \DateTimeImmutable('2026-01-01'));
        $user->setLastLoginAt($stamp);
        $this->em->flush();

        $items = $this->repo->listAll();

        self::assertCount(1, $items);
        self::assertNotNull($items[0]->lastLoginAt);
        self::assertSame($stamp->getTimestamp(), $items[0]->lastLoginAt->getTimestamp());
    }

    public function testPrimaryRoleIsAdminWhenRoleAdminPresent(): void
    {
        $this->persistUser('admin@example.com', 'A', 'B', new \DateTimeImmutable(), ['ROLE_ADMIN']);
        $this->persistUser('user@example.com', 'C', 'D', new \DateTimeImmutable());
        $this->em->flush();

        $items = $this->repo->listAll();
        $byEmail = [];
        foreach ($items as $item) {
            $byEmail[$item->email] = $item;
        }

        self::assertSame('admin', $byEmail['admin@example.com']->primaryRole());
        self::assertSame('user', $byEmail['user@example.com']->primaryRole());
    }

    public function testListAllExposesUniqueIdAndSlug(): void
    {
        $this->persistUser('emilie@example.com', 'Émilie', "d'Arc", new \DateTimeImmutable('2026-04-01'));
        $this->em->flush();

        $items = $this->repo->listAll();

        self::assertCount(1, $items);
        self::assertInstanceOf(Ulid::class, $items[0]->uniqueId);
        // AsciiSlugger collapses accents and apostrophes to a clean URL slug.
        self::assertSame('emilie-d-arc', $items[0]->slug);
    }

    public function testFindByUniqueIdReturnsProfile(): void
    {
        $user = $this->persistUser('jean@example.com', 'Jean', 'Dupont', new \DateTimeImmutable('2026-04-01'));
        $this->em->flush();

        $profile = $this->repo->findByUniqueId((string) $user->getUniqueId());

        self::assertNotNull($profile);
        self::assertSame('jean@example.com', $profile->email);
        self::assertSame('jean-dupont', $profile->slug);
        self::assertTrue($profile->hasPasswordAuth);
        self::assertFalse($profile->hasGoogleAuth);
        self::assertFalse($profile->isProfileComplete);
    }

    public function testListWithFunctionReturnsOnlyHolders(): void
    {
        $agent = $this->persistUser('agent@example.com', 'A', 'Gent', new \DateTimeImmutable(), ['ROLE_SECTION_DOSSIERS']);
        $agent->setStaffFunctions([\App\Auth\Domain\StaffFunction::SearchAgent, \App\Auth\Domain\StaffFunction::Closer]);
        $this->persistUser('other@example.com', 'Ot', 'Her', new \DateTimeImmutable(), ['ROLE_SECTION_DOSSIERS']);
        $this->em->flush();

        $searchAgents = $this->repo->listWithFunction(\App\Auth\Domain\StaffFunction::SearchAgent);
        self::assertCount(1, $searchAgents);
        self::assertSame('agent@example.com', $searchAgents[0]->email);

        self::assertSame([], $this->repo->listWithFunction(\App\Auth\Domain\StaffFunction::VisitAgent));
    }

    public function testProfilePrimaryRoleIsStaffForSectionHolders(): void
    {
        $user = $this->persistUser('staff@example.com', 'S', 'T', new \DateTimeImmutable(), ['ROLE_SECTION_DOSSIERS']);
        $this->em->flush();

        $profile = $this->repo->findByUniqueId((string) $user->getUniqueId());

        self::assertNotNull($profile);
        self::assertSame('staff', $profile->primaryRole());
    }

    public function testFindByUniqueIdReturnsNullForUnknownUlid(): void
    {
        self::assertNull($this->repo->findByUniqueId((string) new Ulid()));
    }

    public function testFindByUniqueIdReturnsNullForInvalidString(): void
    {
        self::assertNull($this->repo->findByUniqueId('not-a-ulid'));
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $email, string $first, string $last, \DateTimeImmutable $createdAt, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($first)
            ->setLastName($last)
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt($createdAt);
        $this->em->persist($user);

        return $user;
    }
}
