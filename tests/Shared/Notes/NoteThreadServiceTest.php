<?php

declare(strict_types=1);

namespace App\Tests\Shared\Notes;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactNote;
use App\Contact\Service\ContactNoteThreadAdapter;
use App\Shared\Notes\NoteThreadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Shared follow-up notes workflow, exercised through the contact adapter
 * (the dossier adapter shares the exact same contract).
 */
final class NoteThreadServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NoteThreadService $thread;
    private ContactNoteThreadAdapter $adapter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ContactNote::class.' n WHERE n.authorName = :a')->setParameter('a', 'Thread Author')->execute();
        $this->em->createQuery('DELETE FROM '.Contact::class.' c WHERE c.email LIKE :p')->setParameter('p', '%@note-thread.example')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@note-thread.local')->execute();
        $this->thread = self::getContainer()->get(NoteThreadService::class);
        $this->adapter = self::getContainer()->get(ContactNoteThreadAdapter::class);
    }

    public function testCurrentAuthorUsesTheFullNameAndFallsBackToTheEmail(): void
    {
        $named = $this->seedUser('named@note-thread.local', ['ROLE_SECTION_CONTACTS'], firstName: 'Jane', lastName: 'Doe');
        $this->loginAs($named);
        self::assertSame('Jane Doe', $this->thread->currentAuthor()->displayName);

        $anonymousName = $this->seedUser('bare@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        $this->loginAs($anonymousName);
        self::assertSame('bare@note-thread.local', $this->thread->currentAuthor()->displayName);
    }

    public function testCurrentAuthorDeniesWhenNobodyIsLoggedIn(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);

        $this->expectException(AccessDeniedException::class);

        $this->thread->currentAuthor();
    }

    public function testBeginEditReturnsTheTextForTheAuthor(): void
    {
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [$contact, $note] = $this->seedNote((int) $author->getId());
        $this->loginAs($author);

        $text = $this->thread->beginEdit($this->adapter, (int) $note->getId(), (int) $contact->getId());

        self::assertSame('Premier jet', $text);
    }

    public function testBeginEditIsSilentOnAStaleOrForeignNoteId(): void
    {
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [, $note] = $this->seedNote((int) $author->getId());
        $this->loginAs($author);

        self::assertNull($this->thread->beginEdit($this->adapter, 999999999, 1));
        self::assertNull($this->thread->beginEdit($this->adapter, (int) $note->getId(), 999999999), 'Note of another owner is ignored.');
    }

    public function testEditingSomeoneElsesNoteIsDeniedForNonAdmins(): void
    {
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        $other = $this->seedUser('other@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [$contact, $note] = $this->seedNote((int) $author->getId());
        $this->loginAs($other);

        $this->expectException(AccessDeniedException::class);

        $this->thread->beginEdit($this->adapter, (int) $note->getId(), (int) $contact->getId());
    }

    public function testSaveEditTrimsAndPersistsTheNewText(): void
    {
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [$contact, $note] = $this->seedNote((int) $author->getId());
        $this->loginAs($author);

        $saved = $this->thread->saveEdit($this->adapter, (int) $note->getId(), '  Version corrigée  ', (int) $contact->getId());

        self::assertTrue($saved);
        $this->em->clear();
        self::assertSame('Version corrigée', $this->em->find(ContactNote::class, $note->getId())->getText());
    }

    public function testSaveEditRefusesABlankTextOrAMissingNote(): void
    {
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [$contact, $note] = $this->seedNote((int) $author->getId());
        $this->loginAs($author);

        self::assertFalse($this->thread->saveEdit($this->adapter, (int) $note->getId(), '   ', (int) $contact->getId()));
        self::assertFalse($this->thread->saveEdit($this->adapter, null, 'Texte', (int) $contact->getId()));
        $this->em->clear();
        self::assertSame('Premier jet', $this->em->find(ContactNote::class, $note->getId())->getText());
    }

    public function testDeleteRemovesTheNoteAndIgnoresStaleIds(): void
    {
        $admin = $this->seedUser('admin@note-thread.local', ['ROLE_ADMIN']);
        $author = $this->seedUser('author@note-thread.local', ['ROLE_SECTION_CONTACTS']);
        [$contact, $note] = $this->seedNote((int) $author->getId());
        $noteId = (int) $note->getId();
        $this->loginAs($admin);

        self::assertFalse($this->thread->delete($this->adapter, 999999999, (int) $contact->getId()));
        self::assertTrue($this->thread->delete($this->adapter, $noteId, (int) $contact->getId()), 'An admin can delete any note.');
        $this->em->clear();
        self::assertNull($this->em->find(ContactNote::class, $noteId));
    }

    /**
     * @return array{0: Contact, 1: ContactNote}
     */
    private function seedNote(int $authorId): array
    {
        $contact = (new Contact())
            ->setFirstName('jane')
            ->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@note-thread.example')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day'));
        $note = (new ContactNote())
            ->setContact($contact)
            ->setText('Premier jet')
            ->setAuthorId($authorId)
            ->setAuthorName('Thread Author');
        $this->em->persist($contact);
        $this->em->persist($note);
        $this->em->flush();

        return [$contact, $note];
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles, string $firstName = '', string $lastName = ''): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
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
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
