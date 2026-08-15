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
        self::assertFileDoesNotExist($path);
        self::assertCount(0, $fresh->getPersons()->first()->getDocuments()->first()->getFiles());

        // Piste d'audit : la clôture purge des données client, elle part sur
        // le canal security comme la suppression.
        self::assertNotSame([], array_filter(
            $spy->records,
            static fn (array $record): bool => 'Dossier closed' === $record['message'],
        ), 'The closure must be written to the security channel.');

        // Reopening lifts the closure but never restores the files.
        $component->reopen();
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNull($fresh->getClosedAt());
        self::assertCount(0, $fresh->getPersons()->first()->getDocuments()->first()->getFiles());
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

    private function persistDossier(): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@example.com')
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-000042')
            ->setPairingCode('ABE78L')
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
