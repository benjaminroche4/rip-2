<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\DocumentTypeSelection;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierDocumentNamer;
use App\Dossier\Service\DossierDocumentRemover;
use App\Dossier\Service\DossierDocumentRequester;
use App\Dossier\Service\DossierDocumentReviewer;
use App\Dossier\Service\DossierFileModuleViewFactory;
use App\Dossier\Service\DossierProgressCalculator;
use App\Dossier\Service\DossierStepValidator;
use App\Visit\Domain\VisitSummary;
use App\Visit\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
 *
 * The component orchestrates the modal state machines; the mutations live
 * in the Dossier services (requester, reviewer, remover) and the display
 * projections in DossierFileModuleViewFactory.
 */
#[AsLiveComponent(name: 'Dossier:Modules', template: 'components/Dossier/Modules.html.twig')]
final class Modules
{
    use DossiersSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    /** Admin path prefix, needed to build the file download links. */
    #[LiveProp]
    public string $adminPrefix = '';

    /**
     * Module keys currently unfolded, tous ouverts par défaut (décision
     * utilisateur, août 2026). Kept server-side: the morph restores
     * whatever `open` the server rendered, so a DOM-only state would be lost
     * at the next re-render.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $openModules = ['file', 'visit', 'payment'];

    /**
     * Cadenas anti-missclick du module Dossier : verrouillé par défaut à
     * chaque chargement, les gestes de mutation (demande, statut, retrait)
     * ne répondent qu'une fois déverrouillé.
     */
    #[LiveProp]
    public bool $fileLocked = true;

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

    /** True while the reminder modal is open (one reminder for the card). */
    #[LiveProp]
    public bool $resending = false;

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

    /** True while the deposit-lock confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingDepositLock = false;

    /** File id whose display name is being edited inline, or null. */
    #[LiveProp]
    public ?int $renamingFileId = null;

    /** Draft of the file display name being edited, without extension. */
    #[LiveProp(writable: true)]
    public string $renameDraft = '';

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly VisitRepository $visits,
        private readonly DossierProgressCalculator $progress,
        private readonly DossierDocumentRequester $requester,
        private readonly DossierDocumentReviewer $reviewer,
        private readonly DossierDocumentRemover $remover,
        private readonly DossierFileModuleViewFactory $views,
        private readonly DossierStepValidator $stepValidator,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly \App\Dossier\Service\DossierDriveProvisioner $driveProvisioner,
        private readonly \App\Dossier\Service\DossierEventLogger $events,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
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
     * "x/y déposées" on the module card summary.
     *
     * @return array{deposited: int, total: int}
     */
    public function getPieceCounts(): array
    {
        return $this->views->pieceCounts($this->dossier());
    }

    /** True as soon as one file has been deposited on the dossier. */
    public function getHasDepositedFiles(): bool
    {
        return $this->views->hasDepositedFiles($this->dossier());
    }

    /** Public deposit link (pairing code prefilled), null before any request. */
    public function getDepositUrl(): ?string
    {
        return $this->views->depositUrl($this->dossier());
    }

    /** Pairing code shown next to the deposit link so staff can read it out. */
    public function getPairingCode(): ?string
    {
        return $this->dossier()->getPairingCode();
    }

    /**
     * Visits booked for this dossier: one chronological list, most recent
     * first, for the visit module card. The upcoming split only feeds the
     * counter; "past" starts at the previous midnight, like the archive.
     *
     * @return array{all: list<VisitSummary>, upcoming: list<VisitSummary>}
     */
    public function getDossierVisits(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $all = array_reverse($this->visits->findByDossierSummaries($this->dossierId));
        $upcoming = array_values(array_filter($all, static fn (VisitSummary $summary): bool => $summary->scheduledAt >= $today));

        return ['all' => $all, 'upcoming' => $upcoming];
    }

    /**
     * Biens refusés par le client sur ce dossier (retour client "Refuse"
     * posé sur la fiche visite), pour le badge ambre du module Visites.
     * Recalculé à chaque rendu, jamais dénormalisé.
     */
    public function getRefusedVisitCount(): int
    {
        return $this->visits->countRefusedByDossier($this->dossierId);
    }

    /**
     * Étape ouverte pour un module donné : le parcours de la fiche est
     * séquentiel, chaque module attend la validation de l'étape précédente.
     * Même calcul que la barre d'onglets (DossierProgressCalculator), donc
     * aucune dérive possible entre l'écran et les gardes serveur.
     */
    public function isModuleUnlocked(string $key): bool
    {
        $step = DossierStep::tryFrom($key);

        return null !== $step && $this->progress->forDossier($this->dossier())->isUnlocked($step);
    }

    /** Étape à valider pour ouvrir ce module, pour l'infobulle du cadenas. */
    public function blockingStepLabel(string $key): ?string
    {
        $step = DossierStep::tryFrom($key);

        return null !== $step
            ? $this->progress->forDossier($this->dossier())->blockedBy($step)?->labelKey()
            : null;
    }

    /** Garde des actions du module Dossier (pièces justificatives). */
    public function isUnlocked(): bool
    {
        return $this->isModuleUnlocked(DossierStep::File->value);
    }

    /** État du verrou de l'espace de dépôt public, pour le bouton. */
    public function isDepositLocked(): bool
    {
        return $this->dossier()->isDepositLocked();
    }

    /** Date de pose du verrou, pour le bandeau d'alerte. */
    public function getDepositLockedAt(): ?\DateTimeImmutable
    {
        return $this->dossier()->getDepositLockedAt();
    }

    /**
     * Verrouille / déverrouille l'espace de dépôt public du dossier : le
     * client qui s'identifie voit "accès verrouillé, réessayez plus tard".
     * Rien n'est purgé : c'est une pause, pas une clôture.
     */
    /**
     * Entry point of the deposit-lock button: locking asks for confirmation
     * (the tenants lose access), unlocking restores service directly.
     */
    #[LiveAction]
    public function askDepositLock(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
        if ($this->dossier()->isDepositLocked()) {
            $this->toggleDepositLock();

            return;
        }
        $this->confirmingDepositLock = true;
    }

    #[LiveAction]
    public function cancelDepositLock(): void
    {
        $this->ensureAdmin();
        $this->confirmingDepositLock = false;
    }

    #[LiveAction]
    public function confirmDepositLock(): void
    {
        $this->ensureAdmin();
        $this->confirmingDepositLock = false;
        if (!$this->isFileMutable() || $this->dossier()->isDepositLocked()) {
            return;
        }
        $this->toggleDepositLock();
    }

    #[LiveAction]
    public function toggleDepositLock(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }

        $dossier = $this->dossier();
        $locked = !$dossier->isDepositLocked();
        $dossier->setDepositLockedAt($locked ? $this->clock->now() : null);
        $this->events->log($dossier, $locked ? 'deposit_locked' : 'deposit_unlocked');
        $this->em->flush();
        $this->emit('dossier-documents:changed');

        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans($locked ? 'admin.toast.depositLocked' : 'admin.toast.depositUnlocked')]);
    }

    #[LiveAction]
    public function toggleFileLock(): void
    {
        $this->ensureAdmin();
        $this->fileLocked = !$this->fileLocked;
    }

    /** Étape déverrouillée ET cadenas ouvert : seule combinaison qui mute. */
    private function isFileMutable(): bool
    {
        return $this->isUnlocked() && !$this->fileLocked;
    }

    /** État validé du module, pour le pied de card. */
    public function isModuleValidated(string $key): bool
    {
        $step = DossierStep::tryFrom($key);

        return null !== $step && $this->progress->forDossier($this->dossier())->isValidated($step);
    }

    /**
     * Le "Rouvrir" n'est proposé que sur la dernière étape validée : rouvrir
     * plus tôt laisserait une étape validée derrière une étape ouverte.
     */
    public function canReopenModule(string $key): bool
    {
        $step = DossierStep::tryFrom($key);
        if (null === $step || !$this->isModuleValidated($key)) {
            return false;
        }
        $next = $step->next();

        return null === $next || !$this->progress->forDossier($this->dossier())->isValidated($next);
    }

    /** Bouton "Valider" en bas du module : déverrouille la card suivante. */
    #[LiveAction]
    public function validateModule(#[LiveArg] string $module): void
    {
        $this->ensureAdmin();
        $step = DossierStep::tryFrom($module);
        if (null === $step) {
            return;
        }

        if ($this->stepValidator->validate($this->dossier(), $step)) {
            // Enchaînement naturel : l'onglet suivant s'ouvre (l'URL est
            // mise à jour avant le morph déclenché par progress:changed).
            if (null !== $step->next()) {
                $this->dispatchBrowserEvent('dossier-step:validated', ['next' => $step->next()->value]);
            }
            // La barre d'onglets vit dans le chrome de page : un morph Turbo
            // la met à jour (déverrouillage de l'onglet suivant).
            $this->dispatchBrowserEvent('dossier-progress:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.stepValidated')]);
        }
    }

    #[LiveAction]
    public function reopenModule(#[LiveArg] string $module): void
    {
        $this->ensureAdmin();
        $step = DossierStep::tryFrom($module);
        if (null === $step) {
            return;
        }

        if ($this->stepValidator->reopen($this->dossier(), $step)) {
            $this->dispatchBrowserEvent('dossier-progress:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.stepReopened')]);
        }
    }

    /**
     * Tenants of the file module with their requested pieces.
     *
     * @return list<array{id: int, name: string, documents: list<array<string, mixed>>}>
     */
    public function getTenants(): array
    {
        return $this->views->tenants($this->dossier());
    }

    /**
     * @return list<DossierDocumentType>
     */
    public function getDocumentTypes(): array
    {
        return DossierDocumentType::cases();
    }

    /**
     * People of the dossier who can receive the request email.
     *
     * @return list<array{id: int, name: string, email: string, primary: bool}>
     */
    public function getRecipients(): array
    {
        return $this->views->recipients($this->dossier());
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
        if (!$this->isFileMutable()) {
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

        if ([] === DocumentTypeSelection::clean($this->selectedTypes)) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.none';

            return;
        }
        if ([] === DocumentTypeSelection::newFor($tenant, $this->selectedTypes)) {
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
        if (!$this->isFileMutable()) {
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

        $types = DocumentTypeSelection::newFor($tenant, $this->selectedTypes);
        if ([] === $types) {
            $this->pickerError = 'admin.dossiers.show.modules.file.error.allRequested';

            return;
        }

        $this->requester->request($dossier, $tenant, $recipient, $types);
        $this->emit('dossier-documents:changed');

        // Confirmation en toast, jamais dans la card (décision utilisateur,
        // août 2026) : le composant reste sur la page, l'event suffit.
        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('admin.dossiers.show.modules.file.sent', ['%email%' => (string) $recipient->getEmail()]),
        ]);
        $this->pickerId = null;
        $this->selectedTypes = [];
        $this->recipientId = '';
        $this->pickerError = '';
    }

    /**
     * Sets a piece's review status (requested, received, validated) from
     * the status dropdown. Choosing "refused" opens the refusal modal
     * instead (optional reason + notification email).
     */
    #[LiveAction]
    public function chooseStatus(#[LiveArg] int $key, #[LiveArg] string $status): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
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

        $this->reviewer->applyStatus($this->dossier(), $document, $target);
        $this->emit('dossier-documents:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.statusUpdated')]);
    }

    /**
     * Confirms the refusal from the modal: persists the optional reason,
     * flips the status and emails the tenant so they deposit a new version.
     */
    #[LiveAction]
    public function confirmRefusal(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
        $document = $this->document((int) $this->refusingId);

        $this->reviewer->refuse($this->dossier(), $document, $this->refusalReason);
        $this->emit('dossier-documents:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.pieceRefused')]);

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
        if (!$this->isFileMutable()) {
            return;
        }
        $document = $this->document((int) $this->editingDescriptionId);

        $this->reviewer->updateDescription($this->dossier(), $document, $this->descriptionDraft);
        $this->emit('dossier-documents:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.descriptionSaved')]);

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

    /** Inline rename of a deposited file's display name (pencil on the row). */
    #[LiveAction]
    public function editFileName(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $file = $this->file($key);
        $this->renamingFileId = (int) $file->getId();
        // The extension is not editable: it reflects the stored content.
        $this->renameDraft = pathinfo((string) $file->getOriginalName(), \PATHINFO_FILENAME);
    }

    #[LiveAction]
    public function saveFileName(EntityManagerInterface $em, DossierDocumentNamer $namer): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable() || null === $this->renamingFileId) {
            return;
        }
        $file = $this->file((int) $this->renamingFileId);

        $draft = trim($this->renameDraft);
        if ('' !== $draft) {
            $extension = pathinfo((string) $file->getOriginalName(), \PATHINFO_EXTENSION);
            $file->setOriginalName($namer->sanitize($draft.('' !== $extension ? '.'.$extension : '')));
            $em->flush();
            $this->emit('dossier-documents:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.fileRenamed')]);
        }

        $this->renamingFileId = null;
        $this->renameDraft = '';
    }

    #[LiveAction]
    public function cancelFileName(): void
    {
        $this->ensureAdmin();
        $this->renamingFileId = null;
        $this->renameDraft = '';
    }

    /**
     * Types déjà demandés au locataire du picker : cochés et grisés dans la
     * modale de sélection (une pièce existante ne se redemande pas).
     *
     * @return list<string>
     */
    public function getPickerRequestedTypes(): array
    {
        if (null === $this->pickerId) {
            return [];
        }

        $types = [];
        foreach ($this->person($this->pickerId)->getDocuments() as $document) {
            $type = $document->getType();
            if (null !== $type) {
                $types[] = $type->value;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Bouton "Créer le dossier dans le Drive" : visible uniquement quand le
     * Drive est configuré et que l'arborescence n'existe pas encore (un
     * driveFolderId présent = déjà créée, le bouton disparaît).
     */
    public function getCanProvisionDrive(): bool
    {
        return $this->driveProvisioner->isEnabled() && null === $this->dossier()->getDriveFolderId();
    }

    /** Crée l'arborescence Drive vide : dossier racine + un sous-dossier par locataire. */
    #[LiveAction]
    public function provisionDrive(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable() || !$this->getCanProvisionDrive()) {
            return;
        }

        $dossier = $this->dossier();
        if (null === $this->driveProvisioner->ensureDossierFolder($dossier)) {
            return;
        }
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                $this->driveProvisioner->ensurePersonFolder($dossier, $person);
            }
        }
        $this->driveProvisioner->syncManagerShare($dossier);

        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.driveProvisioned')]);
    }

    /** True as soon as one piece of the dossier still awaits a deposit. */
    public function hasPendingPieces(): bool
    {
        foreach ($this->dossier()->getPersons() as $person) {
            if ([] !== DocumentTypeSelection::pendingFor($person)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opens the single reminder modal of the card: one gesture relaunches
     * every piece still awaiting a deposit (requested or refused), all
     * tenants included; the admin picks which emails receive the reminder.
     */
    #[LiveAction]
    public function askResend(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable() || !$this->hasPendingPieces()) {
            return;
        }

        $this->resending = true;
        // Default recipients: every tenant with pending pieces that is
        // reachable, else the first reachable person of the dossier.
        $recipients = $this->getRecipients();
        $ids = array_column($recipients, 'id');
        $preselected = [];
        foreach ($this->dossier()->getPersons() as $person) {
            if ([] !== DocumentTypeSelection::pendingFor($person) && \in_array((int) $person->getId(), $ids, true)) {
                $preselected[] = (string) $person->getId();
            }
        }
        if ([] === $preselected && [] !== $recipients) {
            $preselected = [(string) $recipients[0]['id']];
        }
        $this->resendRecipientIds = $preselected;
    }

    #[LiveAction]
    public function cancelResend(): void
    {
        $this->ensureAdmin();
        $this->resending = false;
        $this->resendRecipientIds = [];
        $this->resendError = '';
    }

    /** Sends the reminder to every recipient checked in the modal. */
    #[LiveAction]
    public function confirmResend(): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
        // Batched double-click: the first call already sent and closed.
        if (!$this->resending) {
            return;
        }
        $dossier = $this->dossier();

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

        // Un seul geste pour toute la card : chaque locataire ayant des
        // pièces en attente est relancé (un email par locataire, listant
        // ses pièces à lui).
        $sent = [];
        $failed = false;
        foreach ($dossier->getPersons() as $tenant) {
            $types = DocumentTypeSelection::pendingFor($tenant);
            if ([] === $types) {
                continue;
            }
            $outcome = $this->requester->resend($dossier, $tenant, $recipients, $types);
            $sent = array_values(array_unique([...$sent, ...$outcome['sent']]));
            $failed = $failed || (bool) $outcome['failed'];
        }

        if ([] !== $sent) {
            $this->emit('dossier-documents:changed');
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('admin.dossiers.show.modules.file.sent', ['%email%' => implode(', ', $sent)]),
            ]);
        }
        if ($failed) {
            $this->resendError = 'admin.dossiers.show.modules.file.resendModal.sendFailed';

            return;
        }

        $this->resending = false;
        $this->resendRecipientIds = [];
        $this->resendError = '';
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
     * database, after the confirmation modal.
     */
    #[LiveAction]
    public function deleteFile(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
        $this->confirmDeleteFileId = null;

        $this->remover->deleteFile($this->dossier(), $key);
        $this->emit('dossier-documents:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.fileDeleted')]);
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
     * longer needed), deposited files included.
     */
    #[LiveAction]
    public function deletePiece(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        if (!$this->isFileMutable()) {
            return;
        }
        $this->confirmDeletePieceId = null;

        $this->remover->deletePiece($this->dossier(), $this->document($key));
        $this->emit('dossier-documents:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.pieceDeleted')]);
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function file(int $id): DossierDocumentFile
    {
        foreach ($this->dossier()->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles() as $file) {
                    if ($file->getId() === $id) {
                        return $file;
                    }
                }
            }
        }

        throw new NotFoundHttpException('File not found on this dossier.');
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
