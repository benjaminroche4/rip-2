<?php

declare(strict_types=1);

namespace App\Tests\Admin\Twig\Components;

use App\Admin\Entity\Document;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Component-level checks for Admin:DocumentList:
 *  - non-admin cannot mount
 *  - empty state renders when no documents exist
 *  - rows render when documents exist, ordered by createdAt desc
 *  - the add-button appears in both states
 */
final class DocumentListComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;
    use InteractsWithLiveComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Document::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $this->seedUser('user@example.com');

        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
    }

    public function testAdminSeesEmptyStateWhenNoDocuments(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertSame(0, $component->getTotalCount());

        $html = (string) $this->renderTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringContainsString('data-testid="document-list-empty"', $html);
        self::assertStringContainsString('data-testid="document-add-button"', $html);
        self::assertStringNotContainsString('data-testid="document-table"', $html);
    }

    public function testAdminSeesRowsOrderedByCreatedAtDesc(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->seedDocument('Premier', 'First', 'premier', new \DateTimeImmutable('2026-01-01'));
        $this->seedDocument('Deuxième', 'Second', 'deuxieme', new \DateTimeImmutable('2026-03-15'));
        $this->seedDocument('Troisième', 'Third', 'troisieme', new \DateTimeImmutable('2026-04-10'));
        $this->em->flush();

        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertSame(3, $component->getTotalCount());

        $docs = $component->getDocuments();
        self::assertSame('Troisième', $docs[0]->getNameFr());
        self::assertSame('Deuxième', $docs[1]->getNameFr());
        self::assertSame('Premier', $docs[2]->getNameFr());

        $html = (string) $this->renderTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringContainsString('data-testid="document-table"', $html);
        self::assertStringContainsString('Troisième', $html);
        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('data-testid="document-add-button"', $html);
    }

    public function testDeleteActionRemovesTheDocument(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $toDelete = $this->seedDocument('À supprimer', 'To delete', 'a-supprimer', new \DateTimeImmutable());
        $toKeep = $this->seedDocument('À garder', 'To keep', 'a-garder', new \DateTimeImmutable());
        $this->em->flush();
        $deleteId = $toDelete->getId();
        $keepId = $toKeep->getId();

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($admin);

        $component->call('delete', ['id' => $deleteId]);

        $remaining = $this->em->getRepository(Document::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame($keepId, $remaining[0]->getId());
    }

    public function testDeleteActionIsNoOpOnUnknownId(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->seedDocument('Existe', 'Exists', 'existe', new \DateTimeImmutable());
        $this->em->flush();

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($admin);

        $component->call('delete', ['id' => 999999]);

        // Existing rows untouched on unknown id (race protection).
        self::assertCount(1, $this->em->getRepository(Document::class)->findAll());
    }

    public function testDeleteActionRequiresAdmin(): void
    {
        $user = $this->seedUser('user@example.com');
        $doc = $this->seedDocument('Test', 'Test', 'test', new \DateTimeImmutable());
        $this->em->flush();

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($user);

        $this->expectException(AccessDeniedException::class);
        $component->call('delete', ['id' => $doc->getId()]);
    }

    public function testPinnedDocumentsAreSurfacedFirst(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        // Older, not pinned — should land last.
        $this->seedDocument('Ancien', 'Old', 'ancien', new \DateTimeImmutable('2026-01-01'));
        // Newer, not pinned — second within the unpinned bucket.
        $this->seedDocument('Récent', 'Recent', 'recent', new \DateTimeImmutable('2026-03-01'));
        // Pinned but older — must float above both unpinned despite the date.
        $pinned = $this->seedDocument('Épinglé', 'Pinned', 'epingle', new \DateTimeImmutable('2026-02-01'));
        $pinned->setPinned(true);
        $this->em->flush();

        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $docs = $component->getDocuments();

        self::assertSame('Épinglé', $docs[0]->getNameFr(), 'Pinned doc must come first regardless of date.');
        self::assertSame('Récent', $docs[1]->getNameFr());
        self::assertSame('Ancien', $docs[2]->getNameFr());
    }

    public function testPinnedBadgeIsRenderedOnlyForPinnedRows(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->seedDocument('Normal', 'Normal', 'normal', new \DateTimeImmutable());
        $pinned = $this->seedDocument('Épinglé', 'Pinned', 'epingle', new \DateTimeImmutable());
        $pinned->setPinned(true);
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $html = (string) $this->renderTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        // Exactly one badge — the unpinned row stays plain.
        self::assertSame(1, substr_count($html, 'data-testid="document-pinned-badge"'));
        self::assertStringContainsString('Épinglé', $html);
    }

    public function testEditActionEmitsEditRequestedWithId(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $doc = $this->seedDocument('À éditer', 'To edit', 'a-editer', new \DateTimeImmutable());
        $this->em->flush();

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($admin);

        $component->call('edit', ['id' => $doc->getId()]);

        $this->assertComponentEmitEvent($component, 'document:edit-requested');
    }

    public function testEditActionIsNoOpOnUnknownId(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($admin);

        $component->call('edit', ['id' => 999999]);

        // Nothing emitted for an unknown doc — the dialog must not pop open
        // in that case.
        self::assertNull($component->getEmittedEvent($component->render(), 'document:edit-requested'));
    }

    public function testEditActionRequiresAdmin(): void
    {
        $user = $this->seedUser('user@example.com');
        $doc = $this->seedDocument('Test', 'Test', 'test', new \DateTimeImmutable());
        $this->em->flush();

        $component = $this->createLiveComponent('Admin:DocumentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ])->actingAs($user);

        $this->expectException(AccessDeniedException::class);
        $component->call('edit', ['id' => $doc->getId()]);
    }

    public function testEditButtonIsRenderedPerRow(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $doc = $this->seedDocument('Existe', 'Exists', 'existe', new \DateTimeImmutable());
        $this->em->flush();

        $this->loginAs('admin@example.com');
        $html = (string) $this->renderTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('data-testid="document-edit-trigger-'.$doc->getId().'"', $html);
        self::assertStringContainsString('data-live-action-param="edit"', $html);
    }

    public function testListenerInvalidatesCache(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->seedDocument('Un', 'One', 'un', new \DateTimeImmutable());
        $this->em->flush();

        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertCount(1, $component->getDocuments());

        // Simulate the document:created emit by adding a new doc and
        // invoking the listener directly: the cache must be cleared so
        // the next getDocuments() picks up the new row.
        $this->seedDocument('Deux', 'Two', 'deux', new \DateTimeImmutable());
        $this->em->flush();
        $component->onDocumentCreated();

        self::assertCount(2, $component->getDocuments());
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
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedDocument(string $nameFr, string $nameEn, string $slug, \DateTimeImmutable $createdAt): Document
    {
        $doc = (new Document())
            ->setNameFr($nameFr)
            ->setNameEn($nameEn)
            ->setSlug($slug)
            ->setCategory(\App\Admin\Domain\DocumentCategory::OTHER)
            ->setCreatedAt($createdAt);
        $this->em->persist($doc);

        return $doc;
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
