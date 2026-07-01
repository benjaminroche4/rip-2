<?php

namespace App\Tests\Admin;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down the ROLE_EDITOR contract: an editor may access ONLY the Outils
 * section of the back-office, and is denied everywhere else (dashboard, users,
 * payments). ROLE_ADMIN still reaches Outils via the role hierarchy — covered
 * by AdminAccessTest.
 */
final class EditorAccessTest extends WebTestCase
{
    private const EDITOR_EMAIL = 'editor-test@example.com';
    private const PASSWORD = 'password';

    private KernelBrowser $client;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        $em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();
        $em->createQuery('DELETE FROM '.Contact::class)->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get('security.user_password_hasher');

        $editor = (new User())
            ->setEmail(self::EDITOR_EMAIL)
            ->setFirstName('Test')
            ->setLastName('Editor')
            ->setRoles(['ROLE_EDITOR'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $editor->setPassword($hasher->hashPassword($editor, self::PASSWORD));

        $em->persist($editor);
        $em->flush();

        $this->client->loginUser($editor);
    }

    private function adminUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin';
    }

    public function testEditorCanSeeToolsPage(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl().'/outils');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Outils');
        self::assertCount(1, $crawler->filter('[data-testid="tools-page"]'));
    }

    public function testEditorCanSeeToolsDocumentsPage(): void
    {
        $this->client->request('GET', $this->adminUrl().'/outils/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Documents');
    }

    public function testEditorSidebarShowsOnlyTools(): void
    {
        $this->client->request('GET', $this->adminUrl().'/outils');

        self::assertResponseIsSuccessful();
        // Tools link present, admin-only links absent for an editor.
        self::assertSelectorExists('aside a[href$="/admin/outils"]');
        self::assertSelectorNotExists('aside a[href$="/admin/utilisateurs"]');
        self::assertSelectorNotExists('aside a[href$="/admin/paiements"]');
    }

    public function testEditorIsDeniedOnDashboard(): void
    {
        $this->client->request('GET', $this->adminUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditorIsDeniedOnUsers(): void
    {
        $this->client->request('GET', $this->adminUrl().'/utilisateurs');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditorIsDeniedOnPayments(): void
    {
        $this->client->request('GET', $this->adminUrl().'/paiements');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixOnToolsReturns404ForEditor(): void
    {
        $this->client->request('GET', '/fr/00000000000000000000000000000000/admin/outils');

        self::assertResponseStatusCodeSame(404);
    }
}
