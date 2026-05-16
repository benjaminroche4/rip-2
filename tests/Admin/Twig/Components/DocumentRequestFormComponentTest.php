<?php

declare(strict_types=1);

namespace App\Tests\Admin\Twig\Components;

use App\Admin\Entity\Document;
use App\Admin\Entity\DocumentRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Component-level checks for Admin:DocumentRequestForm:
 *  - mount requires ROLE_ADMIN
 *  - mount pre-fills one PersonRequest (so the admin starts on a usable form)
 *  - the form renders all the structural test ids the UI relies on
 */
final class DocumentRequestFormComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.DocumentRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.Document::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testMountRequiresAdmin(): void
    {
        $this->seedUser('user@example.com');
        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
    }

    public function testMountSeedsOnePerson(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertNotNull($component->request);
        self::assertCount(1, $component->request->getPersons(), 'A blank PersonRequest should be pre-filled on mount.');
    }

    public function testRenderedFormExposesAllSections(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');

        $html = (string) $this->renderTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('data-testid="document-request-form"', $html);
        self::assertStringContainsString('data-testid="document-request-person"', $html);
        self::assertStringContainsString('data-testid="document-request-add-person"', $html);
        self::assertStringContainsString('data-testid="document-request-typology"', $html);
        self::assertStringContainsString('data-testid="document-request-drive"', $html);
        self::assertStringContainsString('data-testid="document-request-language"', $html);
        self::assertStringContainsString('data-testid="document-request-submit"', $html);
    }

    /** @param list<string> $roles */
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

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
