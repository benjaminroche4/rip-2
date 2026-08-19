<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:Modules file module: the per-tenant document request flow (piece
 * selection, recipient choice, request email) and the requested pieces
 * table with its status toggle.
 */
final class ModulesTest extends KernelTestCase
{
    use InteractsWithTwigComponents;
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@modules-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testItRequestsDocumentsAndSendsTheEmail(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountModules($dossier);

        $component->openPicker($tenant->getId());
        self::assertSame($tenant->getId(), $component->pickerId);

        $component->selectedTypes = ['identity', 'payslips'];
        $component->pickerNext();
        self::assertSame('recipient', $component->pickerStep);
        // Default recipient: the tenant themselves.
        self::assertSame((string) $tenant->getId(), $component->recipientId);

        $component->sendRequest();

        self::assertNull($component->pickerId, 'Modal closes after sending.');
        self::assertEmailCount(1);
        $email = $this->getMailerMessages()[0];
        self::assertSame('jean@modules-test.example', $email->getTo()[0]->getAddress());

        $this->em->clear();
        $documents = $this->em->find(Dossier::class, $dossier->getId())->getPersons()->first()->getDocuments();
        self::assertCount(2, $documents);
        self::assertSame(DossierDocumentStatus::Requested, $documents->first()->getStatus());
    }

    public function testAlreadyRequestedPiecesAreNeverDuplicated(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountModules($dossier);

        $component->openPicker($tenant->getId());
        $component->selectedTypes = ['identity'];
        $component->pickerNext();
        $component->sendRequest();

        // Re-requesting only the same piece is blocked at step 1.
        $component->openPicker($tenant->getId());
        $component->selectedTypes = ['identity'];
        $component->pickerNext();
        self::assertSame('select', $component->pickerStep);
        self::assertSame('admin.dossiers.show.modules.file.error.allRequested', $component->pickerError);

        // With a new piece alongside, only the new one is created.
        $component->selectedTypes = ['identity', 'rib'];
        $component->pickerNext();
        $component->sendRequest();

        $this->em->clear();
        $documents = $this->em->find(Dossier::class, $dossier->getId())->getPersons()->first()->getDocuments();
        self::assertCount(2, $documents);
    }

    public function testEmptySelectionIsRejected(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountModules($dossier);

        $component->openPicker($dossier->getPersons()->first()->getId());
        $component->selectedTypes = ['not-a-type'];
        $component->pickerNext();

        self::assertSame('select', $component->pickerStep);
        self::assertSame('admin.dossiers.show.modules.file.error.none', $component->pickerError);
        self::assertEmailCount(0);
    }

    public function testLockedModuleIgnoresTheRequestFlow(): void
    {
        $dossier = $this->persistDossier(completeSearch: false);
        $component = $this->mountModules($dossier);

        $component->openPicker($dossier->getPersons()->first()->getId());

        self::assertNull($component->pickerId, 'Locked module never opens the picker.');
    }

    public function testLockedModuleIgnoresTheMutationActions(): void
    {
        // Une pièce existe, puis l'étape Recherche est rouverte : le module
        // se reverrouille. Les mutations directes (endpoint /_components/)
        // doivent alors rester des no-ops, pas seulement l'UI masquée.
        $dossier = $this->persistDossier();
        $document = (new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-08-02'));
        $dossier->getPersons()->first()->addDocument($document);
        $this->em->flush();
        $documentId = (int) $document->getId();

        $component = $this->mountModules($dossier);
        $dossier->removeValidatedStep(\App\Dossier\Domain\DossierStep::Search);
        $this->em->flush();
        self::assertFalse($component->isUnlocked());

        $component->chooseStatus($documentId, 'validated');
        $component->deletePiece($documentId);

        $this->em->clear();
        $reloaded = $this->em->find(DossierDocument::class, $documentId);
        self::assertNotNull($reloaded, 'Locked module must not delete pieces.');
        self::assertSame(DossierDocumentStatus::Received, $reloaded->getStatus(), 'Locked module must not change statuses.');
    }

    public function testSetStatusDrivesTheReviewLifecycle(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01')));
        $this->em->flush();
        $documentId = $tenant->getDocuments()->first()->getId();
        $component = $this->mountModules($dossier);

        // Received stamps the deposit date.
        $component->chooseStatus($documentId, 'received');
        $this->em->clear();
        $document = $this->em->find(DossierDocument::class, $documentId);
        self::assertSame(DossierDocumentStatus::Received, $document->getStatus());
        self::assertNotNull($document->getReceivedAt());
        $receivedAt = $document->getReceivedAt();

        // Validating keeps the deposit date. (Refusing goes through the
        // confirmation modal, covered by its own test.)
        $component->chooseStatus($documentId, 'validated');
        $this->em->clear();
        $document = $this->em->find(DossierDocument::class, $documentId);
        self::assertSame(DossierDocumentStatus::Validated, $document->getStatus());
        self::assertEquals($receivedAt, $document->getReceivedAt());

        // Back to requested clears the deposit date; an unknown status is
        // ignored (stale DOM can post anything).
        $component->chooseStatus($documentId, 'requested');
        $component->chooseStatus($documentId, 'not-a-status');
        $this->em->clear();
        $document = $this->em->find(DossierDocument::class, $documentId);
        self::assertSame(DossierDocumentStatus::Requested, $document->getStatus());
        self::assertNull($document->getReceivedAt());
    }

    public function testRequestEmailContainsTheDepositLinkAndPairingCode(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountModules($dossier);

        $component->openPicker($tenant->getId());
        $component->selectedTypes = ['identity'];
        $component->pickerNext();
        $component->sendRequest();

        self::assertEmailCount(1);
        $html = (string) $this->getMailerMessages()[0]->getHtmlBody();
        self::assertStringContainsString('/fr/depot-de-pieces?code=MODUL1', $html);
        self::assertStringContainsString('MODUL1', $html);
    }

    public function testDeleteFileRemovesItAndReopensTheRequestWhenNoneIsLeft(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $document = (new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-08-02'));
        $tenant->addDocument($document);

        $storageDir = (string) self::getContainer()->getParameter('dossier_storage_dir');
        $dossierDir = $storageDir.'/'.$dossier->getReference().'/documents';
        (new Filesystem())->mkdir($dossierDir);
        foreach (['first.pdf', 'second.pdf'] as $storedName) {
            file_put_contents($dossierDir.'/'.$storedName, '%PDF-1.4');
            $document->addFile((new DossierDocumentFile())
                ->setStoredName($storedName)
                ->setOriginalName('original-'.$storedName)
                ->setMimeType('application/pdf')
                ->setSize(8)
                ->setUploadedAt(new \DateTimeImmutable('2026-08-02')));
        }
        $this->em->flush();
        $fileIds = $document->getFiles()->map(static fn (DossierDocumentFile $f): int => (int) $f->getId())->toArray();

        $component = $this->mountModules($dossier);

        // Deleting one file: the piece stays received, the file is gone
        // from disk and database.
        $component->deleteFile($fileIds[0]);
        self::assertFileDoesNotExist($dossierDir.'/first.pdf');
        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertCount(1, $fresh->getFiles());
        self::assertSame(DossierDocumentStatus::Received, $fresh->getStatus());

        // Deleting the last file: the piece goes back to requested so the
        // tenant sees it must be deposited again.
        $component = $this->mountModules($dossier);
        $component->deleteFile($fileIds[1]);
        self::assertFileDoesNotExist($dossierDir.'/second.pdf');
        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertCount(0, $fresh->getFiles());
        self::assertSame(DossierDocumentStatus::Requested, $fresh->getStatus());
        self::assertNull($fresh->getReceivedAt());
    }

    public function testDeletePieceRemovesTheRequestAndItsFiles(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $document = (new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-08-02'));
        $tenant->addDocument($document);

        $storageDir = (string) self::getContainer()->getParameter('dossier_storage_dir');
        $dossierDir = $storageDir.'/'.$dossier->getReference().'/documents';
        (new Filesystem())->mkdir($dossierDir);
        file_put_contents($dossierDir.'/piece.pdf', '%PDF-1.4');
        $document->addFile((new DossierDocumentFile())
            ->setStoredName('piece.pdf')
            ->setOriginalName('carte-identite.pdf')
            ->setMimeType('application/pdf')
            ->setSize(8)
            ->setUploadedAt(new \DateTimeImmutable('2026-08-02')));
        $this->em->flush();
        $documentId = (int) $document->getId();

        $component = $this->mountModules($dossier);

        // Cancelling the modal leaves the piece untouched.
        $component->askDeletePiece($documentId);
        self::assertSame($documentId, $component->confirmDeletePieceId);
        $component->cancelDeletePiece();
        self::assertNull($component->confirmDeletePieceId);
        self::assertNotNull($this->em->find(DossierDocument::class, $documentId));

        $component->askDeletePiece($documentId);
        $component->deletePiece($documentId);

        $this->em->clear();
        self::assertNull($this->em->find(DossierDocument::class, $documentId), 'The piece itself is gone, not just its files.');
        self::assertFileDoesNotExist($dossierDir.'/piece.pdf');
    }

    public function testRefusalGoesThroughTheModalAndNotifiesTheTenant(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-08-02')));
        $this->em->flush();
        $documentId = $tenant->getDocuments()->first()->getId();
        $component = $this->mountModules($dossier);

        // Choosing "refused" opens the modal, the status does not move yet.
        $component->chooseStatus($documentId, 'refused');
        self::assertSame($documentId, $component->refusingId);
        $this->em->clear();
        self::assertSame(DossierDocumentStatus::Received, $this->em->find(DossierDocument::class, $documentId)->getStatus());
        self::assertEmailCount(0);

        // Confirming persists the reason, flips the status and emails the
        // tenant with the reason and the deposit link.
        $component->refusalReason = 'Document illisible';
        $component->confirmRefusal();
        self::assertNull($component->refusingId);
        $this->em->clear();
        $document = $this->em->find(DossierDocument::class, $documentId);
        self::assertSame(DossierDocumentStatus::Refused, $document->getStatus());
        self::assertSame('Document illisible', $document->getRefusalReason());
        self::assertEmailCount(1);
        $email = $this->getMailerMessages()[0];
        self::assertSame('jean@modules-test.example', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Document illisible', (string) $email->getHtmlBody());
        self::assertStringContainsString('/fr/depot-de-pieces?code=MODUL1', (string) $email->getHtmlBody());

        // Any other status clears the refusal reason.
        $component->chooseStatus($documentId, 'received');
        $this->em->clear();
        self::assertNull($this->em->find(DossierDocument::class, $documentId)->getRefusalReason());
    }

    public function testDescriptionIsEditedInlineAndCleared(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $documentId = $tenant->getDocuments()->first()->getId();
        $component = $this->mountModules($dossier);

        $component->editDescription($documentId);
        self::assertSame($documentId, $component->editingDescriptionId);

        $component->descriptionDraft = 'Les 3 derniers mois, recto-verso';
        $component->saveDescription();
        self::assertNull($component->editingDescriptionId);
        $this->em->clear();
        self::assertSame('Les 3 derniers mois, recto-verso', $this->em->find(DossierDocument::class, $documentId)->getDescription());

        // Saving an empty draft clears the description.
        $component->editDescription($documentId);
        $component->descriptionDraft = '   ';
        $component->saveDescription();
        $this->em->clear();
        self::assertNull($this->em->find(DossierDocument::class, $documentId)->getDescription());
    }

    public function testResendRequestEmailsOnlyThePiecesStillAwaited(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable())
            ->setReceivedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $component = $this->mountModules($dossier);

        // The modal opens with the pending tenant preselected as recipient.
        $component->askResend();
        self::assertTrue($component->resending);
        self::assertSame([(string) $tenant->getId()], $component->resendRecipientIds);
        self::assertEmailCount(0);

        $component->confirmResend();

        self::assertFalse($component->resending);
        self::assertEmailCount(1);
        $email = $this->getMailerMessages()[0];
        self::assertSame('jean@modules-test.example', $email->getTo()[0]->getAddress());
        $html = (string) $email->getHtmlBody();
        // Only the piece still awaited, not the already deposited one.
        self::assertStringContainsString('identité', $html);
        self::assertStringNotContainsString('fiches de paie', $html);

        // Batched double-click: the second call is a silent no-op, never a
        // 404 on person(0).
        $component->confirmResend();
        self::assertEmailCount(1);
    }

    public function testResendDoesNothingWhenEveryPieceIsDeposited(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Validated)
            ->setRequestedAt(new \DateTimeImmutable())
            ->setReceivedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $component = $this->mountModules($dossier);

        // Everything deposited: the modal never opens, nothing is sent.
        $component->askResend();

        self::assertFalse($component->resending);
        self::assertEmailCount(0);
    }

    public function testSummaryShowsTheDepositedCounter(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable())
            ->setReceivedAt(new \DateTimeImmutable()));
        $tenant->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringContainsString('module-file-counter', $rendered);
        self::assertStringContainsString('1/2', $rendered);
        // The resend button shows since one piece is still awaited.
        self::assertStringContainsString('module-file-resend', $rendered);
    }

    public function testFileModuleActionsAreLoggedInTheFollowUpThread(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountModules($dossier);

        // Request → event with the piece list and the recipient.
        $component->openPicker($tenant->getId());
        $component->selectedTypes = ['identity'];
        $component->pickerNext();
        $component->sendRequest();

        $documentId = $tenant->getDocuments()->first()->getId();
        $component->chooseStatus($documentId, 'received');
        $component->chooseStatus($documentId, 'validated');

        $kinds = array_map(
            static fn (\App\Dossier\Entity\DossierEvent $event): string => $event->getKind(),
            self::getContainer()->get(\App\Dossier\Repository\DossierEventRepository::class)->listForDossier((int) $dossier->getId()),
        );
        self::assertContains('documents_requested', $kinds);
        self::assertContains('document_status', $kinds);
        self::assertContains('document_validated', $kinds);
    }

    public function testDepositLinkShowsUpOnceARequestExists(): void
    {
        $dossier = $this->persistDossier();

        // No request sent yet: no deposit link at the bottom of the card.
        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringNotContainsString('module-file-deposit-link', $rendered);

        $dossier->getPersons()->first()->addDocument((new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringContainsString('module-file-deposit-link', $rendered);
        self::assertStringContainsString('depot-de-pieces?code=MODUL1', $rendered);
    }

    public function testVisitModuleListsTheDossierVisits(): void
    {
        $dossier = $this->persistDossier();

        $prefix = 'test_admin_prefix_1234567890abcdef';

        // Parcours séquentiel : tant que les pièces ne sont pas validées,
        // le module Visite reste verrouillé.
        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId(), 'adminPrefix' => $prefix]);
        self::assertStringNotContainsString('module-visit-empty', $rendered);
        self::assertStringContainsString('dossier-module-locked', $rendered);

        $this->validateFileStep($dossier);

        // No visit yet: empty state plus the "schedule" link.
        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId(), 'adminPrefix' => $prefix]);
        self::assertStringContainsString('module-visit-empty', $rendered);
        self::assertStringContainsString('module-visit-plan', $rendered);
        self::assertStringNotContainsString('module-visit-counter', $rendered);

        $upcoming = (new \App\Visit\Entity\Visit())
            ->setDossier($dossier)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setScheduledAt(new \DateTimeImmutable('+3 days 10:00'))
            ->setReference('VS-'.random_int(100000, 999999))
            ->setCreatedAt(new \DateTimeImmutable());
        $past = (new \App\Visit\Entity\Visit())
            ->setDossier($dossier)
            ->setAddress('8 avenue Parmentier, 75011 Paris')
            ->setScheduledAt(new \DateTimeImmutable('-10 days 15:00'))
            ->setReference('VS-'.random_int(100000, 999999))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($upcoming);
        $this->em->persist($past);
        $this->em->flush();

        $component = $this->mountModules($dossier);
        $visits = $component->getDossierVisits();
        self::assertSame([$upcoming->getId()], array_map(static fn ($v) => $v->id, $visits['upcoming']));
        // Une seule liste, de la plus récente à la plus ancienne.
        self::assertSame([$upcoming->getId(), $past->getId()], array_map(static fn ($v) => $v->id, $visits['all']));

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId(), 'adminPrefix' => $prefix]);
        self::assertStringNotContainsString('module-visit-empty', $rendered);
        self::assertSame(2, substr_count($rendered, 'data-testid="module-visit-row"'));
        self::assertStringContainsString('module-visit-counter', $rendered);
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $rendered);
        self::assertStringContainsString('8 avenue Parmentier, 75011 Paris', $rendered);
    }

    public function testRefusedVisitsShowTheAmberBadgeOnTheVisitModule(): void
    {
        $dossier = $this->persistDossier();
        $this->validateFileStep($dossier);
        $prefix = 'test_admin_prefix_1234567890abcdef';

        // Sans refus : pas de badge.
        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId(), 'adminPrefix' => $prefix]);
        self::assertStringNotContainsString('dossier-refused-count', $rendered);

        // Un bien refusé par le client (retour client de la fiche visite).
        $refused = (new \App\Visit\Entity\Visit())
            ->setDossier($dossier)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setScheduledAt(new \DateTimeImmutable('-3 days 11:00'))
            ->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setClientDecision(\App\Visit\Domain\ClientDecision::Refused)
            // Seul le refus décidé par le client compte dans le badge.
            ->setRefusalOrigin(\App\Visit\Domain\RefusalOrigin::Client)
            ->setReference('VS-'.random_int(100000, 999999))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($refused);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId(), 'adminPrefix' => $prefix]);
        self::assertStringContainsString('data-testid="dossier-refused-count"', $rendered);
        self::assertStringContainsString('1 bien refus', $rendered);
    }

    /** Valide l'étape Dossier : une pièce demandée, puis validée. */
    private function validateFileStep(Dossier $dossier): void
    {
        $dossier->addValidatedStep(\App\Dossier\Domain\DossierStep::File);
        $this->em->flush();
    }

    public function testToggleModuleTracksTheUnfoldedCards(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountModules($dossier);
        self::assertSame(['file', 'visit', 'payment'], $component->openModules, 'Every module starts unfolded.');

        // Fold two cards: the server-side state mirrors the <details> DOM
        // so the morph re-renders them folded.
        $component->toggleModule('file');
        self::assertSame(['visit', 'payment'], $component->openModules);
        $component->toggleModule('visit');
        self::assertSame(['payment'], $component->openModules);

        // Toggling again unfolds only that card.
        $component->toggleModule('file');
        self::assertSame(['payment', 'file'], $component->openModules);
    }

    private function persistDossier(bool $completeSearch = true): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@modules-test.example')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-000091')
            ->setPairingCode('MODUL1')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);

        if ($completeSearch) {
            // Manual path validation: Personnes and Recherche validated by
            // the staff, so the file module is the one currently open.
            $dossier
                ->addValidatedStep(\App\Dossier\Domain\DossierStep::Persons)
                ->addValidatedStep(\App\Dossier\Domain\DossierStep::Search);
            $dossier->setSearch((new DossierSearch())
                ->setBudget(2000)
                ->setAreas('1,2')
                ->setMoveInAt(new \DateTimeImmutable('+2 months'))
                ->setPropertyType('t2')
                ->setStayDuration('long')
                ->setFurnishing('furnished')
                ->setGuarantorType('physical'));
        }

        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    public function testBackToRequestedStartsANewCycle(): void
    {
        // Cas mensuel (fiche de paie) : repasser la pièce en "Demandée"
        // redate la demande et garde les fichiers déposés en historique.
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $document = (new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Validated)
            ->setRequestedAt(new \DateTimeImmutable('2026-07-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-07-03'));
        $document->addFile((new DossierDocumentFile())
            ->setStoredName('juillet.pdf')
            ->setOriginalName('fiche-juillet.pdf')
            ->setMimeType('application/pdf')
            ->setSize(10)
            ->setUploadedAt(new \DateTimeImmutable('2026-07-03')));
        $tenant->addDocument($document);
        $this->em->flush();
        $documentId = (int) $document->getId();

        $component = $this->mountModules($dossier);
        $component->chooseStatus($documentId, 'requested');

        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $documentId);
        self::assertSame(DossierDocumentStatus::Requested, $fresh->getStatus());
        self::assertNull($fresh->getReceivedAt());
        self::assertGreaterThan(new \DateTimeImmutable('2026-07-01'), $fresh->getRequestedAt(), 'The request is re-dated: new cycle.');
        self::assertCount(1, $fresh->getFiles(), 'Previously deposited files stay as history.');
    }

    public function testDepositLockTogglesAndIsGuardedByThePadlock(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountModules($dossier);
        self::assertFalse($component->isDepositLocked());

        $component->toggleDepositLock();
        $this->em->clear();
        self::assertNotNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());

        $component->toggleDepositLock();
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());

        // Cadenas anti-missclick fermé : le verrou de dépôt ne bouge pas.
        $component->fileLocked = true;
        $component->toggleDepositLock();
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());
    }

    public function testLockingTheDepositGoesThroughTheConfirmationModal(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountModules($dossier);

        // Asking opens the modal without locking anything yet.
        $component->askDepositLock();
        self::assertTrue($component->confirmingDepositLock);
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());

        // Cancelling leaves the deposit open.
        $component->cancelDepositLock();
        self::assertFalse($component->confirmingDepositLock);
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());

        // Confirming locks.
        $component->askDepositLock();
        $component->confirmDepositLock();
        self::assertFalse($component->confirmingDepositLock);
        $this->em->clear();
        self::assertNotNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());

        // Unlocking is direct: no modal, service restored in one click.
        $component->askDepositLock();
        self::assertFalse($component->confirmingDepositLock);
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getDepositLockedAt());
    }

    public function testDepositLockedBannerShowsOnlyWhileLocked(): void
    {
        $dossier = $this->persistDossier();

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringNotContainsString('dossier-deposit-locked-banner', $rendered);

        $dossier->setDepositLockedAt(new \DateTimeImmutable('2026-08-10'));
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringContainsString('dossier-deposit-locked-banner', $rendered);
    }

    public function testDriveProvisionButtonHiddenWhenDriveIsOff(): void
    {
        // Env de test sans Drive : pas de bouton, action no-op.
        $dossier = $this->persistDossier();
        $component = $this->mountModules($dossier);

        self::assertFalse($component->getCanProvisionDrive());
        $component->provisionDrive();
        self::assertNull($dossier->getDriveFolderId());
        $rendered = (string) $this->renderTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        self::assertStringNotContainsString('module-file-provision-drive', $rendered);
    }

    private function mountModules(Dossier $dossier): object
    {
        $component = $this->mountTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);
        // Cadenas anti-missclick ouvert pour les tests de mutation; le
        // comportement verrouillé a son test dédié.
        $component->fileLocked = false;

        return $component;
    }

    public function testPadlockBlocksMutationsUntilUnlocked(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountTwigComponent('Dossier:Modules', ['dossierId' => $dossier->getId()]);

        // Verrouillé par défaut à chaque chargement.
        self::assertTrue($component->fileLocked);
        $component->openPicker($tenant->getId());
        self::assertNull($component->pickerId, 'Locked padlock never opens the picker.');

        $component->toggleFileLock();
        self::assertFalse($component->fileLocked);
        $component->openPicker($tenant->getId());
        self::assertSame($tenant->getId(), $component->pickerId);
    }

    public function testItRenamesADepositedFileKeepingTheExtension(): void
    {
        [$component, $fileId] = $this->mountWithFile();

        $component->editFileName($fileId);
        self::assertSame($fileId, $component->renamingFileId);
        self::assertSame('carte-identite', $component->renameDraft, 'The draft opens without the extension.');

        $component->renameDraft = 'CNI Jean recto / verso';
        $component->saveFileName($this->em, static::getContainer()->get(\App\Dossier\Service\DossierDocumentNamer::class));

        self::assertNull($component->renamingFileId);
        $this->em->clear();
        // Renamed, extension preserved, hostile characters sanitized.
        self::assertSame('CNI Jean recto verso.pdf', $this->em->find(DossierDocumentFile::class, $fileId)->getOriginalName());
    }

    public function testRenamingWithAnEmptyDraftKeepsTheName(): void
    {
        [$component, $fileId] = $this->mountWithFile();

        $component->editFileName($fileId);
        $component->renameDraft = '   ';
        $component->saveFileName($this->em, static::getContainer()->get(\App\Dossier\Service\DossierDocumentNamer::class));

        self::assertNull($component->renamingFileId, 'The editor closes without saving.');
        $this->em->clear();
        self::assertSame('carte-identite.pdf', $this->em->find(DossierDocumentFile::class, $fileId)->getOriginalName());
    }

    public function testPadlockBlocksTheRename(): void
    {
        [$component, $fileId] = $this->mountWithFile();

        $component->editFileName($fileId);
        $component->renameDraft = 'nouveau nom';
        $component->fileLocked = true;
        $component->saveFileName($this->em, static::getContainer()->get(\App\Dossier\Service\DossierDocumentNamer::class));

        $this->em->clear();
        self::assertSame('carte-identite.pdf', $this->em->find(DossierDocumentFile::class, $fileId)->getOriginalName());
    }

    /**
     * @return array{0: object, 1: int}
     */
    private function mountWithFile(): array
    {
        $dossier = $this->persistDossier();
        $document = (new DossierDocument())
            ->setType(\App\Dossier\Domain\DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'));
        $dossier->getPersons()->first()->addDocument($document);
        $file = (new DossierDocumentFile())
            ->setStoredName('stored.pdf')
            ->setOriginalName('carte-identite.pdf')
            ->setMimeType('application/pdf')
            ->setSize(8)
            ->setUploadedAt(new \DateTimeImmutable('2026-08-02'));
        $document->addFile($file);
        $this->em->flush();

        return [$this->mountModules($dossier), (int) $file->getId()];
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@modules-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
