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

    public function testFeedKeepsOnlyNotesEventsLiveInTheTimeline(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->persistNote($contact, (int) $admin->getId(), 'Note avant changement');
        $this->loginAs($admin);

        self::getContainer()->get(\App\Contact\Repository\ContactRepository::class)
            ->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::InProgress, 'First Last', null);

        $feed = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()])->getFeed();

        self::assertCount(1, $feed, 'Status events stay out of the notes thread.');
        self::assertArrayHasKey('note', $feed[0]);
    }

    public function testFeedPagination(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        for ($i = 1; $i <= 7; ++$i) {
            $this->persistNote($contact, (int) $admin->getId(), 'Note '.$i);
        }
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        self::assertCount(5, $component->getFeed());
        self::assertSame(2, $component->getHiddenFeedCount());

        $component->showMoreFeed();
        self::assertCount(7, $component->getFeed());
        self::assertSame(0, $component->getHiddenFeedCount());
    }

    public function testAdminCanReplyToANote(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $parent = $this->persistNote($contact, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $component->startReply((int) $parent->getId());
        self::assertSame((int) $parent->getId(), $component->replyingToId);

        $component->replyText = '  Première réponse.  ';
        $component->addReply();

        self::assertNull($component->replyingToId, 'The composer closes after sending.');
        $feed = $component->getFeed();
        self::assertCount(1, $feed, 'A reply never becomes a top-level entry.');
        self::assertCount(1, $feed[0]['replies']);
        self::assertSame('Première réponse.', $feed[0]['replies'][0]['note']->text);
        self::assertSame((int) $parent->getId(), $feed[0]['replies'][0]['note']->parentId);
    }

    public function testRepliesReadChronologicallyUnderTheirParent(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $parent = $this->persistNote($contact, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $repo = self::getContainer()->get(\App\Contact\Repository\ContactNoteRepository::class);
        $first = $repo->add($contact, 'Réponse 1', (int) $admin->getId(), 'First Last', null, $parent);
        $first->setCreatedAt(new \DateTimeImmutable('-2 hours'));
        $second = $repo->add($contact, 'Réponse 2', (int) $admin->getId(), 'First Last', null, $parent);
        $second->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $feed = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()])->getFeed();

        self::assertCount(1, $feed);
        self::assertSame(
            ['Réponse 1', 'Réponse 2'],
            array_map(static fn (array $row): string => $row['note']->text, $feed[0]['replies']),
            'Replies read oldest first, like a conversation.',
        );
    }

    public function testReplyingToAReplyAttachesToTheRootNote(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $parent = $this->persistNote($contact, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $repo = self::getContainer()->get(\App\Contact\Repository\ContactNoteRepository::class);
        $reply = $repo->add($contact, 'Réponse', (int) $admin->getId(), 'First Last', null, $parent);

        // Depth is capped at one: a reply targeting a reply lands under the root.
        $nested = $repo->add($contact, 'Réponse à la réponse', (int) $admin->getId(), 'First Last', null, $reply);
        self::assertSame($parent->getId(), $nested->getParentNote()?->getId());
    }

    public function testReplyToAnotherThreadsNoteIsIgnored(): void
    {
        $contact = $this->persistContact();
        $otherContact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $foreignNote = $this->persistNote($otherContact, (int) $admin->getId(), "Note d'un autre lead");
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $component->startReply((int) $foreignNote->getId());
        self::assertNull($component->replyingToId, 'A note from another lead never opens the composer.');

        $component->replyingToId = (int) $foreignNote->getId();
        $component->replyText = 'Tentative';
        $component->addReply();
        self::assertCount(0, $component->getFeed(), 'Nothing persists on a cross-thread reply.');
    }

    public function testDeletingTheParentRemovesItsReplies(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $parent = $this->persistNote($contact, (int) $admin->getId(), 'Note mère');
        $repo = self::getContainer()->get(\App\Contact\Repository\ContactNoteRepository::class);
        $repo->add($contact, 'Réponse', (int) $admin->getId(), 'First Last', null, $parent);
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->delete((int) $parent->getId());

        $this->em->clear();
        self::assertCount(0, $repo->listForContact((int) $contact->getId()), 'DB cascade removes the replies with the parent.');
    }

    public function testNonSectionMemberCannotReply(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $user = $this->seedUser('nobody@notes-test.local', []);
        $this->em->flush();
        $parent = $this->persistNote($contact, (int) $admin->getId(), 'Note mère');

        $this->loginAs($admin);
        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->loginAs($user);
        $component->replyingToId = (int) $parent->getId();
        $component->replyText = 'Interdit';
        $this->expectException(AccessDeniedException::class);
        $component->addReply();
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
        // Being the author is no longer enough: the contacts section is a
        // prerequisite (a demoted member loses edit rights on old notes).
        $author = $this->seedUser('author@notes-test.local', ['ROLE_SECTION_CONTACTS']);
        $demotedAuthor = $this->seedUser('demoted@notes-test.local', []);
        $other = $this->seedUser('other@notes-test.local', ['ROLE_SECTION_CONTACTS']);
        $admin = $this->seedUser('admin@notes-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $note = $this->persistNote($contact, (int) $author->getId(), 'Ma note');

        $checker = self::getContainer()->get('security.authorization_checker');

        $this->loginAs($author);
        self::assertTrue($checker->isGranted(ContactNoteVoter::EDIT, $note), 'Author can edit their note.');

        $this->loginAs($other);
        self::assertFalse($checker->isGranted(ContactNoteVoter::EDIT, $note), 'Another non-admin user cannot.');
        self::assertFalse($checker->isGranted(ContactNoteVoter::DELETE, $note));

        // Author of the note, but out of the contacts section: refused.
        $demotedNote = $this->persistNote($contact, (int) $demotedAuthor->getId(), 'Ancienne note');
        $this->loginAs($demotedAuthor);
        self::assertFalse($checker->isGranted(ContactNoteVoter::EDIT, $demotedNote), 'A demoted author loses edit rights.');

        $this->loginAs($admin);
        self::assertTrue($checker->isGranted(ContactNoteVoter::DELETE, $note), 'Admin can always.');
    }

    public function testHistoryRendersStatusChangesAndRecapEvents(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('history-admin@notes-test.local', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        /** @var \App\Contact\Repository\ContactEventRepository $events */
        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class);
        $events->record($contact, \App\Contact\Domain\ContactStatus::InProgress, null, 'Julien Moreau', null);
        $events->recordRecapSent($contact, false, 'Julien Moreau', null);

        /** @var \App\Admin\Twig\Components\ContactNotes $component */
        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);

        $history = $component->getHistory();
        // "Reçu le" ouvre la chronologie, puis les événements en ordre.
        self::assertCount(3, $history);
        self::assertStringContainsString('Reçu le', $history[0]['text']);
        self::assertStringContainsString('En cours', $history[1]['text']);
        self::assertSame('Julien Moreau', $history[1]['authorName']);
        self::assertStringContainsString('Récapitulatif', $history[2]['text']);
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
            // Always in the past, whatever the time of day the suite runs:
            // the reception entry must sort before status events.
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));

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
