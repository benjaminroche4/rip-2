<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactNote;
use App\Contact\Security\ContactNoteVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactNotesTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ContactNote::class)->execute();
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@notes-test.local')->execute();
    }

    public function testAdminCanAddANote(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->newNote = '  Rappelé le client, rdv fixé.  ';
        $component->add();

        $notes = $component->getFeed();
        self::assertCount(1, $notes);
        self::assertSame('Rappelé le client, rdv fixé.', $notes[0]['note']->text);
        self::assertSame('First Last', $notes[0]['note']->authorName);
        self::assertSame('', $component->newNote);
    }

    public function testEmptyNoteIsIgnored(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->newNote = '   ';
        $component->add();

        self::assertCount(0, $component->getFeed());
    }

    public function testAuthorCanEditTheirNote(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $note = $this->persistNote($contact, (int) $admin->getId(), 'Premier jet');
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->startEdit((int) $note->getId());
        self::assertSame('Premier jet', $component->editingText);

        $component->editingText = 'Version corrigée';
        $component->saveEdit();

        self::assertNull($component->editingNoteId);
        self::assertSame('Version corrigée', $component->getFeed()[0]['note']->text);
    }

    public function testDeleteRemovesTheNote(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $note = $this->persistNote($contact, (int) $admin->getId(), 'À supprimer');
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->delete((int) $note->getId());

        self::assertCount(0, $component->getFeed());
    }

    public function testFeedMergesNotesAndStatusEvents(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->persistNote($contact, (int) $admin->getId(), 'Note avant changement');
        $this->loginAs($admin);

        self::getContainer()->get(\App\Contact\Repository\ContactRepository::class)
            ->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::InProgress, 'First Last', null);

        $feed = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()])->getFeed();

        self::assertCount(2, $feed);
        self::assertArrayHasKey('event', $feed[0], 'The status change is the newest entry.');
        self::assertSame(\App\Contact\Domain\ContactStatus::InProgress, $feed[0]['event']->status);
        self::assertArrayHasKey('note', $feed[1]);
    }

    public function testFeedFilterAndPagination(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        for ($i = 1; $i <= 7; ++$i) {
            $this->persistNote($contact, (int) $admin->getId(), 'Note '.$i);
        }
        $this->loginAs($admin);

        self::getContainer()->get(\App\Contact\Repository\ContactRepository::class)
            ->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::InProgress, 'First Last', null);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        // 8 entries total (7 notes + 1 event), capped at 5.
        self::assertSame(['all' => 8, 'notes' => 7, 'events' => 1], $component->getFeedCounts());
        self::assertCount(5, $component->getFeed());
        self::assertSame(3, $component->getHiddenFeedCount());

        $component->showMoreFeed();
        self::assertCount(8, $component->getFeed());
        self::assertSame(0, $component->getHiddenFeedCount());

        $component->filterFeed('events');
        self::assertCount(1, $component->getFeed());
        self::assertArrayHasKey('event', $component->getFeed()[0]);

        $component->filterFeed('notes');
        self::assertCount(5, $component->getFeed(), 'Switching filters resets the cap.');
        self::assertArrayHasKey('note', $component->getFeed()[0]);

        // Unknown filter is ignored.
        $component->filterFeed('bogus');
        self::assertSame('notes', $component->feedFilter);
    }

    public function testNonAdminCannotMount(): void
    {
        $user = $this->seedUser('user@notes-test.local', []);
        $this->em->flush();
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => 1]);
    }

    public function testVoterAllowsAuthorAndAdminOnly(): void
    {
        $contact = $this->persistContact();
        $author = $this->seedUser('author@notes-test.local', []);
        $other = $this->seedUser('other@notes-test.local', []);
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $note = $this->persistNote($contact, (int) $author->getId(), 'Ma note');

        $checker = self::getContainer()->get('security.authorization_checker');

        $this->loginAs($author);
        self::assertTrue($checker->isGranted(ContactNoteVoter::EDIT, $note), 'Author can edit their note.');

        $this->loginAs($other);
        self::assertFalse($checker->isGranted(ContactNoteVoter::EDIT, $note), 'Another non-admin user cannot.');
        self::assertFalse($checker->isGranted(ContactNoteVoter::DELETE, $note));

        $this->loginAs($admin);
        self::assertTrue($checker->isGranted(ContactNoteVoter::DELETE, $note), 'Admin can always.');
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')
            ->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('today 10:00'));

        $this->em->persist($contact);

        return $contact;
    }

    private function persistNote(Contact $contact, int $authorId, string $text): ContactNote
    {
        $note = (new ContactNote())
            ->setContact($contact)
            ->setText($text)
            ->setAuthorId($authorId)
            ->setAuthorName('First Last');

        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }

    private function seedUser(string $email, array $roles): User
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

    private function loginAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
