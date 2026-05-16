<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\PersonRole;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Locks the DocumentRequestList LiveComponent contract:
 *  - first page shows PER_PAGE rows + load-more button when there's overflow
 *  - more() grows the visible set
 *  - empty state when no requests
 *  - non-admin → AccessDenied
 */
final class DocumentRequestListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PER_PAGE = 25;
    private const ADMIN_PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.DocumentRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testRendersEmptyStateWhenNoRequests(): void
    {
        $this->seedAdmin('admin@example.com');
        $this->em->flush();
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentRequestList', ['adminPrefix' => self::ADMIN_PREFIX]);

        self::assertTrue($component->isEmpty());
        self::assertSame(0, $component->getTotalCount());
        self::assertSame([], $component->getItems());
        self::assertFalse($component->hasMore());
    }

    public function testRendersFirstPageAndLoadMoreWhenOverflow(): void
    {
        $this->seedAdmin('admin@example.com');
        // 30 > PER_PAGE (25) → load-more must surface.
        for ($i = 0; $i < 30; ++$i) {
            $this->seedRequest(new \DateTimeImmutable('-'.$i.' hours'));
        }
        $this->em->flush();
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentRequestList', ['adminPrefix' => self::ADMIN_PREFIX]);

        self::assertSame(30, $component->getTotalCount());
        self::assertCount(self::PER_PAGE, $component->getItems());
        self::assertTrue($component->hasMore());

        $html = (string) $this->renderTwigComponent('Admin:DocumentRequestList', ['adminPrefix' => self::ADMIN_PREFIX]);
        self::assertStringContainsString('data-testid="recent-requests-table"', $html);
        // Download link template renders the admin prefix in the URL.
        self::assertStringContainsString('/admin/outils/documents/demande/', $html);
    }

    public function testMoreActionGrowsTheVisibleSet(): void
    {
        $this->seedAdmin('admin@example.com');
        for ($i = 0; $i < 30; ++$i) {
            $this->seedRequest(new \DateTimeImmutable('-'.$i.' hours'));
        }
        $this->em->flush();
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentRequestList', ['adminPrefix' => self::ADMIN_PREFIX]);
        $component->more();

        // 30 total; after more() we ask for 2 × PER_PAGE = 50, clamped at 30.
        self::assertCount(30, $component->getItems());
        self::assertFalse($component->hasMore());
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $this->seedUser('user@example.com');
        $this->em->flush();
        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:DocumentRequestList', ['adminPrefix' => self::ADMIN_PREFIX]);
    }

    private function seedRequest(\DateTimeImmutable $createdAt): DocumentRequest
    {
        $request = (new DocumentRequest())
            ->setTypology(HouseholdTypology::ONE_TENANT)
            ->setLanguage(RequestLanguage::FR)
            ->setDriveLink('https://drive.example.test/'.bin2hex(random_bytes(4)))
            ->setCreatedAt($createdAt);

        $person = (new PersonRequest())
            ->setRole(PersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setPosition(0);
        $request->addPerson($person);

        $this->em->persist($request);

        return $request;
    }

    private function seedAdmin(string $email): User
    {
        return $this->seedUser($email, ['ROLE_ADMIN']);
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
