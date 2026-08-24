<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierNote;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Security\DossierNoteVoter;
use App\Dossier\Service\DossierDriveProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:Notes behaviour: interactive follow-up thread (add / edit / delete
 * with the voter, pagination) plus the manager assignment chips, mirroring
 * the contact detail page.
 */
final class DossierNotesTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@dossier-notes-test.local')->execute();
    }

    public function testAdminCanAddANote(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->newNote = '  Premier point avec la famille.  ';
        $component->add();

        $feed = $component->getFeed();
        self::assertCount(1, $feed);
        self::assertSame('Premier point avec la famille.', $feed[0]['note']->text);
        self::assertSame('Admin Staff', $feed[0]['note']->authorName);
        self::assertSame('', $component->newNote);
    }

    public function testEmptyNoteIsIgnored(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->newNote = '   ';
        $component->add();

        self::assertCount(0, $component->getFeed());
    }

    public function testAuthorCanEditTheirNote(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $note = $this->persistNote($dossier, (int) $admin->getId(), 'Premier jet');
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->startEdit((int) $note->getId());
        self::assertSame('Premier jet', $component->editingText);

        $component->editingText = 'Version corrigée';
        $component->saveEdit();

        self::assertNull($component->editingNoteId);
        self::assertSame('Version corrigée', $component->getFeed()[0]['note']->text);
    }

    public function testDeleteRemovesTheNote(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $note = $this->persistNote($dossier, (int) $admin->getId(), 'À supprimer');
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->delete((int) $note->getId());

        self::assertCount(0, $component->getFeed());
    }

    public function testAdminCanReplyToANote(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $parent = $this->persistNote($dossier, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
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
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $parent = $this->persistNote($dossier, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $repo = self::getContainer()->get(\App\Dossier\Repository\DossierNoteRepository::class);
        $first = $repo->add($dossier, 'Réponse 1', (int) $admin->getId(), 'Admin Staff', null, $parent);
        $first->setCreatedAt(new \DateTimeImmutable('-2 hours'));
        $second = $repo->add($dossier, 'Réponse 2', (int) $admin->getId(), 'Admin Staff', null, $parent);
        $second->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $feed = $this->mountNotes($dossier)->getFeed();

        self::assertCount(1, $feed);
        self::assertSame(
            ['Réponse 1', 'Réponse 2'],
            array_map(static fn (array $row): string => $row['note']->text, $feed[0]['replies']),
            'Replies read oldest first, like a conversation.',
        );
    }

    public function testReplyingToAReplyAttachesToTheRootNote(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $parent = $this->persistNote($dossier, (int) $admin->getId(), 'Note mère');
        $this->loginAs($admin);

        $repo = self::getContainer()->get(\App\Dossier\Repository\DossierNoteRepository::class);
        $reply = $repo->add($dossier, 'Réponse', (int) $admin->getId(), 'Admin Staff', null, $parent);

        // Depth is capped at one: a reply targeting a reply lands under the root.
        $nested = $repo->add($dossier, 'Réponse à la réponse', (int) $admin->getId(), 'Admin Staff', null, $reply);
        self::assertSame($parent->getId(), $nested->getParentNote()?->getId());
    }

    public function testReplyToAnotherThreadsNoteIsIgnored(): void
    {
        $dossier = $this->persistDossier();
        $other = $this->persistDossier('DS-000043', 'ABE79L');
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $foreignNote = $this->persistNote($other, (int) $admin->getId(), "Note d'un autre dossier");
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->startReply((int) $foreignNote->getId());
        self::assertNull($component->replyingToId, 'A note from another dossier never opens the composer.');

        $component->replyingToId = (int) $foreignNote->getId();
        $component->replyText = 'Tentative';
        $component->addReply();
        self::assertCount(0, $component->getFeed(), 'Nothing persists on a cross-thread reply.');
    }

    public function testDeletingTheParentRemovesItsReplies(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $parent = $this->persistNote($dossier, (int) $admin->getId(), 'Note mère');
        $repo = self::getContainer()->get(\App\Dossier\Repository\DossierNoteRepository::class);
        $repo->add($dossier, 'Réponse', (int) $admin->getId(), 'Admin Staff', null, $parent);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->delete((int) $parent->getId());

        self::assertCount(0, $component->getFeed(), 'The DB cascade removes replies with their parent.');
    }

    public function testLegacyStatusChangeEventsStillRenderTheirLabel(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        // Pre-August-2026 workflow events store the full translation key of
        // the status at the time of the change: the old keys must keep a
        // label forever, or the feed shows the raw key.
        $event = (new \App\Dossier\Entity\DossierEvent())
            ->setDossier($dossier)
            ->setKind('status_changed')
            ->setPayload(['status' => 'admin.dossiers.status.choice.searching'])
            ->setAuthorName('Système')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($event);
        $this->em->flush();

        $component = $this->mountNotes($dossier);
        $texts = array_column($component->getEvents(), 'text');
        $statusText = implode(' ', $texts);
        self::assertStringContainsString('Recherche en cours', $statusText);
        self::assertStringNotContainsString('admin.dossiers.status.choice', $statusText);
    }

    public function testAdminCanSetThenChangeTheOfferButNeverClearIt(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);

        // Cadenas anti-missclick : verrouillé par défaut, le changement est
        // refusé tant qu'il n'est pas ouvert.
        try {
            $component->chooseOffer('accompagne');
            self::fail('A locked offer must reject changes.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
        }
        $component->toggleOfferLock();

        $component->chooseOffer('accompagne');
        self::assertSame('accompagne', $this->em->find(\App\Dossier\Entity\Dossier::class, $dossier->getId())->getOffer());

        $component->chooseOffer('confie');
        self::assertSame('confie', $this->em->find(\App\Dossier\Entity\Dossier::class, $dossier->getId())->getOffer());

        // Chaque changement laisse sa trace dans le fil de suivi.
        $texts = array_column($component->getEvents(), 'text');
        self::assertStringContainsString('Accompagné', implode(' ', $texts));

        // Un dossier garde toujours une formule : le retrait est refusé.
        try {
            $component->chooseOffer('');
            self::fail('Clearing the offer must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
        }
        self::assertSame('confie', $this->em->find(\App\Dossier\Entity\Dossier::class, $dossier->getId())->getOffer());
    }

    public function testSectionStaffCannotChangeTheOffer(): void
    {
        $dossier = $this->persistDossier();
        $staff = $this->persistUser('staff-offer', ['ROLE_SECTION_DOSSIERS']);
        $this->loginAs($staff);

        $component = $this->mountNotes($dossier);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $component->chooseOffer('accompagne');
    }

    public function testUnknownOfferIsRejected(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->toggleOfferLock();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $component->chooseOffer('premium');
    }

    public function testAuditEventsAppearInTheActivityFeedNotTheComments(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $component->assignManager((int) $admin->getId(), self::getContainer()->get(DossierDriveProvisioner::class));

        // Events live in the fil de suivi, never in the comments thread.
        $events = $component->getEvents();
        self::assertStringContainsString('assigné', $events[0]['text']);
        self::assertNotNull($events[0]['authorName']);
        self::assertCount(0, $component->getFeed());
    }

    public function testStatusIsAutomaticAndFollowsTheValidatedSteps(): void
    {
        // Plus de sélecteur manuel : le statut suit la validation des étapes.
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        // Dossier neuf : l'étape en attente est Personnes.
        self::assertSame(\App\Dossier\Domain\DossierStatus::Persons, $component->getEffectiveStatus());

        $validator = self::getContainer()->get(\App\Dossier\Service\DossierStepValidator::class);
        $validator->validate($dossier, \App\Dossier\Domain\DossierStep::Persons);
        $validator->validate($dossier, \App\Dossier\Domain\DossierStep::Search);

        // Personnes et Recherche validées : le Dossier (pièces) est en attente.
        self::assertSame(\App\Dossier\Domain\DossierStatus::File, $component->getEffectiveStatus());
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertSame(\App\Dossier\Domain\DossierStatus::File, $fresh->getStatus());
    }

    public function testClosureOverridesTheStepStatus(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        $dossier->setStatus(\App\Dossier\Domain\DossierStatus::Finalization);
        $dossier->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame(\App\Dossier\Domain\DossierStatus::Closed, $component->getEffectiveStatus());
    }

    public function testNoteDeletionIsDirectFromTheActionsMenu(): void
    {
        // Same gesture as the contact notes drawer: no modal, the "…" menu
        // deletes directly (the client plays the fade-exit animation).
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $note = $this->persistNote($dossier, (int) $admin->getId(), 'À supprimer');
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        self::assertCount(1, $component->getFeed());

        $component->delete((int) $note->getId());
        self::assertCount(0, $component->getFeed());
    }

    public function testClosurePurgesFilesRotatesTheCodeAndReopens(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        // One deposited file on disk + database.
        $tenant = $dossier->getPersons()->first();
        $document = (new \App\Dossier\Entity\DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(\App\Dossier\Domain\DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable())
            ->setReceivedAt(new \DateTimeImmutable());
        $tenant->addDocument($document);
        $document->addFile((new \App\Dossier\Entity\DossierDocumentFile())
            ->setStoredName('closure-test.pdf')
            ->setOriginalName('piece.pdf')
            ->setMimeType('application/pdf')
            ->setSize(8)
            ->setUploadedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $storageDir = (string) self::getContainer()->getParameter('dossier_storage_dir');
        $path = $storageDir.'/'.$dossier->getReference().'/documents/closure-test.pdf';
        (new \Symfony\Component\Filesystem\Filesystem())->mkdir(\dirname($path));
        file_put_contents($path, '%PDF-1.4');
        $oldCode = $dossier->getPairingCode();

        $spy = $this->spyOnSecurityLog();
        $component = $this->mountNotes($dossier);

        // The modal must be confirmed; cancelling changes nothing.
        $component->askClose();
        self::assertTrue($component->confirmingClosure);
        $component->cancelClose();
        self::assertFalse($component->confirmingClosure);
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getClosedAt());

        $component->askClose();
        $component->confirmClose();

        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNotNull($fresh->getClosedAt());
        self::assertNotSame($oldCode, $fresh->getPairingCode(), 'The emailed deposit links must die.');
        // Closing is pure archiving: nothing is deleted, the staff keeps
        // every deposited file.
        self::assertFileExists($path);
        self::assertCount(1, $fresh->getPersons()->first()->getDocuments()->first()->getFiles());

        // Piste d'audit : la clôture coupe l'accès client au dépôt, elle
        // part sur le canal security.
        self::assertNotSame([], array_filter(
            $spy->records,
            static fn (array $record): bool => 'Dossier closed' === $record['message'],
        ), 'The closure must be written to the security channel.');

        // Reopening lifts the closure with the files still in place.
        $component->reopen();
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNull($fresh->getClosedAt());
        self::assertCount(1, $fresh->getPersons()->first()->getDocuments()->first()->getFiles());
        self::assertFileExists($path);
    }

    public function testFeedPagination(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        for ($i = 1; $i <= 7; ++$i) {
            $this->persistNote($dossier, (int) $admin->getId(), 'Note '.$i);
        }
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);

        self::assertCount(5, $component->getFeed());
        self::assertSame(2, $component->getHiddenFeedCount());

        $component->showMoreFeed();
        self::assertCount(7, $component->getFeed());
        self::assertSame(0, $component->getHiddenFeedCount());
    }

    public function testAssignsAndUnassignsTheManager(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $editor = $this->persistUser('editor', ['ROLE_SECTION_DOSSIERS']);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);
        self::assertNull($component->getManager());

        $component->assignManager((int) $editor->getId(), self::getContainer()->get(DossierDriveProvisioner::class));
        self::assertSame($editor->getId(), $component->getManager()?->id);
        $this->em->clear();
        self::assertSame($editor->getId(), $this->em->find(Dossier::class, $dossier->getId())->getManager()?->getId());

        $component->assignManager(0, self::getContainer()->get(DossierDriveProvisioner::class));
        self::assertNull($component->getManager());
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getManager());
    }

    public function testCannotAssignARegularUser(): void
    {
        $dossier = $this->persistDossier();
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $plain = $this->persistUser('plain', []);
        $this->loginAs($admin);

        $component = $this->mountNotes($dossier);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $component->assignManager((int) $plain->getId(), self::getContainer()->get(DossierDriveProvisioner::class));
    }

    public function testNotesDrawerOpensAndClosesServerSide(): void
    {
        $dossier = $this->persistDossier();
        $this->loginAs($this->persistUser('admin', ['ROLE_ADMIN']));
        $component = $this->mountNotes($dossier);

        self::assertFalse($component->notesOpen);

        $component->openNotes();
        self::assertTrue($component->notesOpen);

        $component->closeNotes();
        self::assertFalse($component->notesOpen);
    }

    public function testNonAdminCannotMount(): void
    {
        $user = $this->persistUser('plain', []);
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Dossier:Notes', ['dossierId' => 1, 'adminPrefix' => 'x']);
    }

    public function testVoterAllowsAuthorAndAdminOnly(): void
    {
        $dossier = $this->persistDossier();
        $author = $this->persistUser('author', []);
        $other = $this->persistUser('other', []);
        $admin = $this->persistUser('admin', ['ROLE_ADMIN']);
        $note = $this->persistNote($dossier, (int) $author->getId(), 'Ma note');

        $checker = self::getContainer()->get('security.authorization_checker');

        $this->loginAs($author);
        self::assertTrue($checker->isGranted(DossierNoteVoter::EDIT, $note), 'Author can edit their note.');

        $this->loginAs($other);
        self::assertFalse($checker->isGranted(DossierNoteVoter::EDIT, $note), 'Another non-admin user cannot.');
        self::assertFalse($checker->isGranted(DossierNoteVoter::DELETE, $note));

        $this->loginAs($admin);
        self::assertTrue($checker->isGranted(DossierNoteVoter::DELETE, $note), 'Admin can always.');
    }

    private function persistDossier(string $reference = 'DS-000042', string $pairingCode = 'ABE78L'): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@example.com')
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference($reference)
            ->setPairingCode($pairingCode)
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function persistNote(Dossier $dossier, int $authorId, string $text): DossierNote
    {
        $note = (new DossierNote())
            ->setDossier($dossier)
            ->setText($text)
            ->setAuthorId($authorId)
            ->setAuthorName('Admin Staff');

        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $slug, array $roles): User
    {
        $user = (new User())
            ->setEmail($slug.'-'.bin2hex(random_bytes(3)).'@dossier-notes-test.local')
            ->setFirstName(ucfirst($slug))->setLastName('Staff')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testTheOfferShowsInTheFollowUpCardOnlyWhenSet(): void
    {
        $dossier = $this->persistDossier();
        $this->loginAs($this->persistUser('staff', ['ROLE_SECTION_DOSSIERS']));

        $rendered = (string) $this->renderTwigComponent('Dossier:Notes', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringNotContainsString('data-testid="dossier-offer"', $rendered, 'No offer: the row stays hidden.');

        $dossier->setOffer('confie');
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Notes', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringContainsString('data-testid="dossier-offer"', $rendered);
        self::assertStringContainsString('Confié', $rendered);
    }

    public function testTheClosedBannerAppearsOnlyOnAClosedDossier(): void
    {
        $dossier = $this->persistDossier();
        $this->loginAs($this->persistUser('staff', ['ROLE_SECTION_DOSSIERS']));

        $rendered = (string) $this->renderTwigComponent('Dossier:ClosedBanner', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringNotContainsString('data-testid="dossier-closed-banner"', $rendered);

        $dossier->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:ClosedBanner', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringContainsString('data-testid="dossier-closed-banner"', $rendered);
        self::assertStringContainsString('Dossier clôturé', $rendered);
    }

    public function testTheClosedBannerNamesWhoArchivedTheDossier(): void
    {
        $dossier = $this->persistDossier();
        $this->loginAs($this->persistUser('staff', ['ROLE_SECTION_DOSSIERS']));

        $notes = $this->mountNotes($dossier);
        $notes->askClose();
        $notes->confirmClose();

        $rendered = (string) $this->renderTwigComponent('Dossier:ClosedBanner', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringContainsString('data-testid="dossier-closed-by"', $rendered);
        // Le nom vient de la piste d'audit, capturé au moment du geste.
        self::assertStringContainsString('Staff Staff', $rendered);
    }

    /** Capture du canal d'audit "security" le temps du test. */
    private function spyOnSecurityLog(): object
    {
        $spy = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        self::getContainer()->set('monolog.logger.security', $spy);

        return $spy;
    }

    private function mountNotes(Dossier $dossier): object
    {
        $component = $this->mountTwigComponent('Dossier:Notes', [
            'dossierId' => (int) $dossier->getId(),
            'adminPrefix' => 'test-prefix',
        ]);
        $component->setLiveResponder(new LiveResponder());

        return $component;
    }

    private function loginAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
