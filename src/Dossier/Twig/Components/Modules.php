<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DocumentStorage;
use App\Dossier\Service\DossierDocumentRefusalMailer;
use App\Dossier\Service\DossierDocumentRequestMailer;
use App\Dossier\Service\DossierEventLogger;
use App\Visit\Domain\VisitSummary;
use App\Visit\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Module cards (Dossier, Visite, Paiement) on the dossier detail page,
 * locked while the search criteria are incomplete. The file module drives
 * the per-tenant document requests: a checkbox modal picks the pieces,
 * a second step picks the email recipient, the request email goes out and
 * the module then lists each requested piece with its status.
 */
#[AsLiveComponent(name: 'Dossier:Modules', template: 'components/Dossier/Modules.html.twig')]
final class Modules
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    /** Admin path prefix, needed to build the file download links. */
    #[LiveProp]
    public string $adminPrefix = '';

    /**
     * Module keys currently unfolded. Kept server-side: the morph restores
     * whatever `open` the server rendered, so a DOM-only state would be lost
     * at the next re-render.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $openModules = [];

    /** Person id whose "select pieces" modal is open, or null. */
    #[LiveProp]
    public ?int $pickerId = null;

    /** Modal step: pieces checkboxes, then the email recipient. */
    #[LiveProp]
    public string $pickerStep = 'select';

    /** @var list<string> Checked piece types (DossierDocumentType values). */
    #[LiveProp(writable: true)]
    public array $selectedTypes = [];

    /** Person id receiving the request email (radio), as a string. */
    #[LiveProp(writable: true)]
    public string $recipientId = '';

    /** Translation key of the current modal error, '' when none. */
    #[LiveProp]
    public string $pickerError = '';

    /** Email the last request was sent to (confirmation line), '' when none. */
    #[LiveProp]
    public string $lastSentTo = '';

    /** Document id whose refusal modal is open, or null. */
    #[LiveProp]
    public ?int $refusingId = null;

    /** Optional refusal reason typed in the modal, shown to the tenant. */
    #[LiveProp(writable: true)]
    public string $refusalReason = '';

    /** File id awaiting deletion confirmation in the modal, or null. */
    #[LiveProp]
    public ?int $confirmDeleteFileId = null;

    /** Piece (document) id awaiting deletion confirmation, or null. */
    #[LiveProp]
    public ?int $confirmDeletePieceId = null;

    /** Tenant id whose resend modal is open, or null. */
    #[LiveProp]
    public ?int $resendingId = null;

    /** @var list<string> Person ids receiving the reminder email (checkboxes). */
    #[LiveProp(writable: true)]
    public array $resendRecipientIds = [];

    /** Translation key of the current resend error, '' when none. */
    #[LiveProp]
    public string $resendError = '';

    /** Document id whose description is being edited inline, or null. */
    #[LiveProp]
    public ?int $editingDescriptionId = null;

    /** Draft of the description being edited, shown to the tenant on save. */
    #[LiveProp(writable: true)]
    public string $descriptionDraft = '';

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly DossierDocumentRequestMailer $mailer,
        private readonly DossierDocumentRefusalMailer $refusalMailer,
        private readonly DocumentStorage $storage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly DossierEventLogger $events,
        private readonly VisitRepository $visits,
        private readonly \App\Dossier\Service\DossierProgressCalculator $progress,
        private readonly \App\Dossier\Service\DossierStatusAdvancer $advancer,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('dossier-search:changed')]
    public function refresh(): void
    {
        // Re-render only: completeness is re-read from the search row.
        $this->ensureAdmin();
    }

    /** Dossier reference, needed by the file download route. */
    public function getReference(): string
    {
        return (string) $this->dossier()->getReference();
    }

    /**
     * "x/y déposées" on the module card summary: pieces holding a deposit
     * (received or validated) over the pieces requested.
     *
     * @return array{deposited: int, total: int}
     */
    public function getPieceCounts(): array
    {
        $deposited = 0;
        $total = 0;
        foreach ($this->dossier()->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                ++$total;
                if (\in_array($document->getStatus(), [DossierDocumentStatus::Received, DossierDocumentStatus::Validated], true)) {
                    ++$deposited;
                }
            }
        }

        return ['deposited' => $deposited, 'total' => $total];
    }

    /** True as soon as one file has been deposited on the dossier. */
    public function getHasDepositedFiles(): bool
    {
        foreach ($this->dossier()->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                if (!$document->getFiles()->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Public deposit link (pairing code prefilled) shown at the bottom of
     * the file module card, so the admin can hand it out directly. It only
     * exists once at least one piece has been requested.
     */
    public function getDepositUrl(): ?string
    {
        $hasRequest = false;
        foreach ($this->dossier()->getPersons() as $person) {
            if (!$person->getDocuments()->isEmpty()) {
                $hasRequest = true;
            }
        }
        if (!$hasRequest) {
            return null;
        }

        return $this->urlGenerator->generate('app_dossier_deposit', [
            'code' => (string) $this->dossier()->getPairingCode(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Visits booked for this dossier, split for the visit module card:
     * upcoming ones first (soonest on top), then past ones (most recent
     * on top). "Past" starts at the previous midnight, like the archive.
     *
     * @return array{upcoming: list<VisitSummary>, past: list<VisitSummary>}
     */
    public function getDossierVisits(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $upcoming = [];
        $past = [];
        foreach ($this->visits->findByDossierSummaries($this->dossierId) as $summary) {
            if ($summary->scheduledAt >= $today) {
                $upcoming[] = $summary;
            } else {
                $past[] = $summary;
            }
        }

        return ['upcoming' => $upcoming, 'past' => array_reverse($past)];
    }

    /**
     * Étape ouverte pour un module donné : le parcours de la fiche est
     * séquentiel, chaque module attend la validation de l'étape précédente.
     * Même calcul que la barre d'onglets (DossierProgressCalculator), donc
     * aucune dérive possible entre l'écran et les gardes serveur.
     */
    public function isModuleUnlocked(string $key): bool
    {
        $step = \App\Dossier\Domain\DossierStep::tryFrom($key);

        return null !== $step && $this->progress->forDossier($this->dossier())->isUnlocked($step);
    }

    /** Étape à valider pour ouvrir ce module, pour l'infobulle du cadenas. */
    public function blockingStepLabel(string $key): ?string
    {
        $step = \App\Dossier\Domain\DossierStep::tryFrom($key);

        return null !== $step
            ? $this->progress->forDossier($this->dossier())->blockedBy($step)?->labelKey()
            : null;
    }

    /** Garde des actions du module Dossier (pièces justificatives). */
    public function isUnlocked(): bool
    {
        return $this->isModuleUnlocked(\App\Dossier\Domain\DossierStep::File->value);
    }

    /**
     * Tenants of the file module with their requested pieces.
     *
     * @return list<array{id: int, name: string, documents: list<array{id: int, typeLabelKey: string, status: string, statusLabelKey: string, requestedAt: \DateTimeImmutable|null, receivedAt: \DateTimeImmutable|null}>}>
     */
    public function getTenants(): array
    {
        $tenants = [];
        foreach ($this->dossier()->getPersons() as $person) {
            if (DossierPersonRole::TENANT !== $person->getRole()) {
                continue;
            }
            $documents = [];
            foreach ($person->getDocuments() as $document) {
                $files = [];
                foreach ($document->getFiles() as $file) {
                    $files[] = [
                        'id' => (int) $file->getId(),
                        'name' => (string) $file->getOriginalName(),
                        'size' => (int) $file->getSize(),
                        'mimeType' => (string) $file->getMimeType(),
                        'uploadedAt' => $file->getUploadedAt(),
                    ];
                }
                $documents[] = [
                    'id' => (int) $document->getId(),
                    'typeLabelKey' => $document->getType()?->labelKey() ?? '',
                    'status' => $document->getStatus()->value,
                    'statusLabelKey' => $document->getStatus()->labelKey(),
                    'requestedAt' => $document->getRequestedAt(),
                    'receivedAt' => $document->getReceivedAt(),
                    'refusalReason' => (string) $document->getRefusalReason(),
                    'description' => (string) $document->getDescription(),
                    'files' => $files,
                ];
            }
            $tenants[] = [
                'id' => (int) $person->getId(),
                'name' => trim(trim((string) $person->getLastName()).' '.trim((string) $person->getFirstName())),
                'documents' => $documents,
            ];
        }

        return $tenants;
    }

    /**
     * @return list<DossierDocumentType>
     */
    public function getDocumentTypes(): array
    {
        return DossierDocumentType::cases();
    }

    /**
     * People of the dossier who can receive the request email (any role,
     * as long as an email exists: the follow-up contact may be the payer).
     *
     * @return list<array{id: int, name: string, email: string, primary: bool}>
     */
    public function getRecipients(): array
    {
        $recipients = [];
        foreach ($this->dossier()->getPersons() as $person) {
            if ('' === trim((string) $person->getEmail())) {
                continue;
            }
            $recipients[] = [
                'id' => (int) $person->getId(),
                'name' => trim(trim((string) $person->getFirstName()).' '.trim((string) $person->getLastName())),
                'email' => (string) $person->getEmail(),
                'primary' => $person->isPrimaryContact(),
            ];
        }

        return $recipients;
    }

    /** Fires alongside the native <details> toggle, to keep the state. */
    #[LiveAction]
    public function toggleModule(#[LiveArg] string $module): void
    {
        $this->ensureAdmin();

        $this->openModules = \in_array($module, $this->openModules, true)
            ? array_values(array_diff($this->openModules, [$module]))
            : [...$this->openModules, $module];
    }

    #[LiveAction]
    public function openPicker(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        if (!$this->isUnlocked()) {
            return;
        }
        $tenant = $this->person($key);
        if (DossierPersonRole::TENANT !== $tenant->getRole()) {
            return;
        }

        $this->pickerId = (int) $tenant->getId();
        $this->pickerStep = 'select';
        $this->selectedTypes = [];
        $this->recipientId = '';
        $this->pickerError = '';
        $this->lastSentTo = '';
    }

    #[LiveAction]
    public function closePicker(): void
    {
        $this->ensureAdmin();
        $this->pickerId = null;
        $this->pickerError = '';
    }

    #[LiveAction]
    public function pickerNext(): void
    {
        $this->ensureAdmin();
        $tenant = $this->person((int) $this->pickerId);

        if ([] === $this->cleanTypes()) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.none';

            return;
        }
        if ([] === $this->newTypes($tenant)) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.allRequested';

            return;
        }

        // Default recipient: the tenant if reachable, else the primary.
        if ('' === $this->recipientId) {
            $recipients = $this->getRecipients();
            $ids = array_column($recipients, 'id');
            if (\in_array((int) $tenant->getId(), $ids, true)) {
                $this->recipientId = (string) $tenant->getId();
            } elseif ([] !== $recipients) {
                $this->recipientId = (string) $recipients[0]['id'];
            }
        }

        $this->pickerStep = 'recipient';
        $this->pickerError = '';
    }

    #[LiveAction]
    public function pickerBack(): void
    {
        $this->ensureAdmin();
        $this->pickerStep = 'select';
        $this->pickerError = '';
    }

    /**
     * Creates the document rows (skipping pieces already requested) and
     * sends the request email to the chosen person.
     */
    #[LiveAction]
    public function sendRequest(): void
    {
        $this->ensureAdmin();
        if (!$this->isUnlocked()) {
            return;
        }
        $dossier = $this->dossier();
        $tenant = $this->person((int) $this->pickerId);

        $recipient = null;
        foreach ($dossier->getPersons() as $person) {
            if ($person->getId() === (int) $this->recipientId && '' !== trim((string) $person->getEmail())) {
                $recipient = $person;
            }
        }
        if (null === $recipient) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.noRecipient';

            return;
        }

        $types = $this->newTypes($tenant);
        if ([] === $types) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.allRequested';

            return;
        }

        $now = $this->clock->now();
        foreach ($types as $type) {
            $tenant->addDocument((new DossierDocument())
                ->setType($type)
                ->setStatus(DossierDocumentStatus::Requested)
                ->setRequestedAt($now));
        }
        $this->events->log($dossier, 'documents_requested', [
            'tenant' => $this->personName($tenant),
            'recipient' => (string) $recipient->getEmail(),
            'pieces' => array_map(static fn (DossierDocumentType $type): string => $type->labelKey(), $types),
        ]);
        $this->em->flush();
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-documents:changed');

        $this->mailer->send($dossier, $tenant, $recipient, $types);

        $this->lastSentTo = (string) $recipient->getEmail();
        $this->pickerId = null;
        $this->selectedTypes = [];
        $this->recipientId = '';
        $this->pickerError = '';
    }

    /**
     * Sets a piece's review status (requested, received, validated) from
     * the status dropdown. Choosing "refused" opens the refusal modal
     * instead (optional reason + notification email). receivedAt keeps
     * tracking the deposit date: stamped when the piece becomes received,
     * cleared when it goes back to requested.
     */
    #[LiveAction]
    public function setStatus(#[LiveArg] int $key, #[LiveArg] string $status): void
    {
        $this->ensureAdmin();
        $target = DossierDocumentStatus::tryFrom($status);
        if (null === $target) {
            return;
        }
        $document = $this->document($key);

        if (DossierDocumentStatus::Refused === $target) {
            $this->refusingId = (int) $document->getId();
            $this->refusalReason = (string) $document->getRefusalReason();

            return;
        }

        $document->setStatus($target);
        $document->setRefusalReason(null);
        if (DossierDocumentStatus::Requested === $target) {
            $document->setReceivedAt(null);
        } elseif (null === $document->getReceivedAt()) {
            $document->setReceivedAt($this->clock->now());
        }
        $this->events->log($this->dossier(), DossierDocumentStatus::Validated === $target ? 'document_validated' : 'document_status', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => $this->personName($document->getPerson()),
            'status' => $target->labelKey(),
        ]);
        $this->em->flush();
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-documents:changed');
    }

    /**
     * Confirms the refusal from the modal: persists the optional reason,
     * flips the status and emails the tenant so they deposit a new version.
     */
    #[LiveAction]
    public function confirmRefusal(): void
    {
        $this->ensureAdmin();
        $document = $this->document((int) $this->refusingId);

        $reason = mb_substr(trim($this->refusalReason), 0, 500);
        $document->setStatus(DossierDocumentStatus::Refused);
        $document->setRefusalReason('' !== $reason ? $reason : null);
        if (null === $document->getReceivedAt()) {
            $document->setReceivedAt($this->clock->now());
        }
        $this->events->log($this->dossier(), 'document_refused', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => $this->personName($document->getPerson()),
            'reason' => $reason,
        ]);
        $this->em->flush();
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-documents:changed');

        $this->refusalMailer->send($this->dossier(), $document);

        $this->refusingId = null;
        $this->refusalReason = '';
    }

    #[LiveAction]
    public function cancelRefusal(): void
    {
        $this->ensureAdmin();
        $this->refusingId = null;
        $this->refusalReason = '';
    }

    /** Opens the inline editor of a piece's public description. */
    #[LiveAction]
    public function editDescription(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $document = $this->document($key);
        $this->editingDescriptionId = (int) $document->getId();
        $this->descriptionDraft = (string) $document->getDescription();
    }

    #[LiveAction]
    public function saveDescription(): void
    {
        $this->ensureAdmin();
        $document = $this->document((int) $this->editingDescriptionId);

        $description = mb_substr(trim($this->descriptionDraft), 0, 500);
        $document->setDescription('' !== $description ? $description : null);
        $this->events->log($this->dossier(), 'description_updated', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => $this->personName($document->getPerson()),
            'description' => $description,
        ]);
        $this->em->flush();
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-documents:changed');

        $this->editingDescriptionId = null;
        $this->descriptionDraft = '';
    }

    #[LiveAction]
    public function cancelDescription(): void
    {
        $this->ensureAdmin();
        $this->editingDescriptionId = null;
        $this->descriptionDraft = '';
    }

    /**
     * Opens the reminder modal for a tenant's pieces still awaiting a
     * deposit (requested or refused): the admin picks which email of the
     * dossier receives the reminder.
     */
    #[LiveAction]
    public function askResend(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $tenant = $this->person($key);
        if ([] === $this->pendingTypes($tenant)) {
            return;
        }

        $this->resendingId = (int) $tenant->getId();
        // Default recipient: the tenant if reachable, else the first one.
        $recipients = $this->getRecipients();
        $ids = array_column($recipients, 'id');
        if (\in_array((int) $tenant->getId(), $ids, true)) {
            $this->resendRecipientIds = [(string) $tenant->getId()];
        } elseif ([] !== $recipients) {
            $this->resendRecipientIds = [(string) $recipients[0]['id']];
        }
    }

    #[LiveAction]
    public function cancelResend(): void
    {
        $this->ensureAdmin();
        $this->resendingId = null;
        $this->resendRecipientIds = [];
        $this->resendError = '';
    }

    /** Sends the reminder to every recipient checked in the modal. */
    #[LiveAction]
    public function confirmResend(): void
    {
        $this->ensureAdmin();
        // Batched double-click: the first call already sent and closed.
        if (null === $this->resendingId) {
            return;
        }
        $dossier = $this->dossier();
        $tenant = $this->person($this->resendingId);

        $types = $this->pendingTypes($tenant);
        if ([] === $types) {
            $this->resendingId = null;

            return;
        }

        $checked = array_map(intval(...), $this->resendRecipientIds);
        $recipients = [];
        foreach ($dossier->getPersons() as $person) {
            if (\in_array((int) $person->getId(), $checked, true) && '' !== trim((string) $person->getEmail())) {
                $recipients[] = $person;
            }
        }
        if ([] === $recipients) {
            return;
        }

        // Every email that actually left must land in the audit trail, even
        // when a later recipient fails: send per recipient, log what went
        // out, then surface the failure without closing the modal.
        $sent = [];
        $failed = false;
        foreach ($recipients as $recipient) {
            try {
                $this->mailer->send($dossier, $tenant, $recipient, $types);
                $sent[] = (string) $recipient->getEmail();
            } catch (\Throwable) {
                $failed = true;
            }
        }
        if ([] !== $sent) {
            $this->events->log($dossier, 'documents_resent', [
                'tenant' => $this->personName($tenant),
                'recipient' => implode(', ', $sent),
                'pieces' => array_map(static fn (DossierDocumentType $type): string => $type->labelKey(), $types),
            ]);
            $this->em->flush();
            $this->emit('dossier-documents:changed');
            $this->lastSentTo = implode(', ', $sent);
        }
        if ($failed) {
            $this->resendError = 'admin.dossiers.show.modules.file.resendModal.sendFailed';

            return;
        }

        $this->resendingId = null;
        $this->resendRecipientIds = [];
        $this->resendError = '';
    }

    /**
     * Pieces of the tenant still awaiting a deposit.
     *
     * @return list<DossierDocumentType>
     */
    private function pendingTypes(DossierPerson $tenant): array
    {
        $types = [];
        foreach ($tenant->getDocuments() as $document) {
            if (\in_array($document->getStatus(), [DossierDocumentStatus::Requested, DossierDocumentStatus::Refused], true)) {
                $type = $document->getType();
                if (null !== $type) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /** Opens the deletion confirmation modal for a deposited file. */
    #[LiveAction]
    public function askDeleteFile(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $this->confirmDeleteFileId = $key;
    }

    #[LiveAction]
    public function cancelDeleteFile(): void
    {
        $this->ensureAdmin();
        $this->confirmDeleteFileId = null;
    }

    /**
     * Deletes a deposited file (unreadable, wrong piece...) from disk and
     * database, after the confirmation modal. When it was the document's
     * last file the piece goes back to "requested" so the tenant sees it
     * must be deposited again.
     */
    #[LiveAction]
    public function deleteFile(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $this->confirmDeleteFileId = null;
        $dossier = $this->dossier();
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles() as $file) {
                    if ($file->getId() !== $key) {
                        continue;
                    }
                    $this->storage->delete($dossier, $file);
                    $fileName = (string) $file->getOriginalName();
                    $document->removeFile($file);
                    if ($document->getFiles()->isEmpty()) {
                        $document->setStatus(DossierDocumentStatus::Requested);
                        $document->setReceivedAt(null);
                    }
                    $this->events->log($dossier, 'document_file_deleted', [
                        'piece' => $document->getType()?->labelKey() ?? '',
                        'tenant' => $this->personName($document->getPerson()),
                        'file' => $fileName,
                    ]);
                    $this->em->flush();
                    $this->advancer->advance($this->dossier());
                    $this->emit('dossier-documents:changed');

                    return;
                }
            }
        }

        throw new NotFoundHttpException('File not found on this dossier.');
    }

    /** Opens the deletion confirmation modal for a whole requested piece. */
    #[LiveAction]
    public function askDeletePiece(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $this->confirmDeletePieceId = $key;
    }

    #[LiveAction]
    public function cancelDeletePiece(): void
    {
        $this->ensureAdmin();
        $this->confirmDeletePieceId = null;
    }

    /**
     * Withdraws a requested piece entirely (wrong type picked, piece no
     * longer needed): the deposited files are removed from storage first,
     * then the row itself, so the tenant stops seeing it in the deposit
     * page. Unlike deleteFile() this leaves nothing behind.
     */
    #[LiveAction]
    public function deletePiece(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $this->confirmDeletePieceId = null;
        $dossier = $this->dossier();
        $document = $this->document($key);
        $person = $document->getPerson();

        foreach ($document->getFiles() as $file) {
            // Storage may be down: the piece must still disappear from the
            // dossier, an orphan blob is preferable to a stuck row.
            try {
                $this->storage->delete($dossier, $file);
            } catch (\Throwable) {
            }
        }

        $this->events->log($dossier, 'document_deleted', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => $this->personName($person),
        ]);

        $person?->removeDocument($document);
        $this->em->remove($document);
        $this->em->flush();
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-documents:changed');
    }

    /**
     * Selected types as validated enums (unknown values are dropped: a
     * stale DOM can post anything).
     *
     * @return list<DossierDocumentType>
     */
    private function cleanTypes(): array
    {
        $types = [];
        foreach (array_unique($this->selectedTypes) as $value) {
            $type = DossierDocumentType::tryFrom((string) $value);
            if (null !== $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Selected types not already requested for this tenant.
     *
     * @return list<DossierDocumentType>
     */
    private function newTypes(DossierPerson $tenant): array
    {
        $existing = [];
        foreach ($tenant->getDocuments() as $document) {
            $existing[] = $document->getType();
        }

        return array_values(array_filter(
            $this->cleanTypes(),
            static fn (DossierDocumentType $type): bool => !\in_array($type, $existing, true),
        ));
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function personName(?DossierPerson $person): string
    {
        return trim(trim((string) $person?->getFirstName()).' '.trim((string) $person?->getLastName()));
    }

    private function document(int $id): DossierDocument
    {
        foreach ($this->dossier()->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                if ($document->getId() === $id) {
                    return $document;
                }
            }
        }

        throw new NotFoundHttpException('Document not found on this dossier.');
    }

    private function person(int $id): DossierPerson
    {
        foreach ($this->dossier()->getPersons() as $person) {
            if ($person->getId() === $id) {
                return $person;
            }
        }

        throw new NotFoundHttpException('Person not found on this dossier.');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
