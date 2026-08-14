<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Repository\ContactRepository;
use App\Contact\Service\VisioInvitationMailer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Status pill + dropdown for the contact detail page. Same rules as the
 * list: ROLE_ADMIN on every entry point, author/date snapshot recorded on
 * change, sidebar badge notified via the shared "contacts:changed" event.
 */
#[AsLiveComponent(name: 'Admin:ContactStatusControl', template: 'components/Admin/ContactStatusControl.html.twig')]
final class ContactStatusControl
{
    use ContactsSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    /** Date de rappel saisie dans l'éditeur (datetime-local), en brouillon. */
    #[LiveProp(writable: true)]
    public string $recallAt = '';

    /**
     * Editor open state for the next step: the picker block shows when no
     * step is set yet or after clicking "Modifier".
     */
    #[LiveProp]
    public bool $editingStep = false;

    /**
     * Staged next-step pick ('' = none). Nothing persists and no email
     * goes out until confirmStep(): picking a step is deliberately a
     * draft, the side effects (visio invites) are too costly to fire on
     * a stray click.
     */
    #[LiveProp]
    public string $pendingStep = '';

    /** True when confirm was blocked because the dated step has no date. */
    #[LiveProp]
    public bool $dateMissing = false;

    /** Staged recontact channel ('' = none), persisted on confirmStep(). */
    #[LiveProp]
    public string $pendingChannel = '';

    /** True when confirming a recontact was blocked for lack of a channel. */
    #[LiveProp]
    public bool $channelMissing = false;

    /** Recontact only: also email the prospect the planned slot + channel. */
    #[LiveProp(writable: true)]
    public bool $notifyClient = false;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly \App\Contact\Repository\ContactEventRepository $events,
        private readonly Security $security,
        private readonly UserRepository $users,
        private readonly VisioInvitationMailer $visioMailer,
        private readonly \App\Contact\Service\RecontactNoticeMailer $recontactNotice,
        private readonly \App\Contact\Service\RecallCalendarSync $recallSync,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('contacts:changed')]
    public function refresh(): void
    {
        // Re-render only: picks up a lead rating set by the LeadQuality
        // component on the same page.
        $this->ensureAdmin();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    /**
     * @return list<ContactStatus>
     */
    public function getStatuses(): array
    {
        return ContactStatus::cases();
    }

    /**
     * @return list<ClosureReason>
     */
    public function getClosureReasons(): array
    {
        return ClosureReason::cases();
    }

    /**
     * @return list<NextStep>
     */
    public function getNextSteps(): array
    {
        return NextStep::cases();
    }

    /**
     * @return list<RecontactChannel>
     */
    public function getRecontactChannels(): array
    {
        return RecontactChannel::cases();
    }

    /** Staged toggle, applied with the rest of the draft on confirmStep(). */
    #[LiveAction]
    public function pickChannel(#[LiveArg] string $channel): void
    {
        $this->ensureAdmin();

        $case = RecontactChannel::tryFrom($channel)
            ?? throw new BadRequestHttpException(\sprintf('Unknown recontact channel "%s".', $channel));

        $this->channelMissing = false;
        $this->dateMissing = false;
        // Clicking the selected chip deselects it.
        $this->pendingChannel = $this->pendingChannel === $case->value ? '' : $case->value;
    }

    #[LiveAction]
    public function setClosureReason(#[LiveArg] string $reason): void
    {
        $this->ensureAdmin();

        $case = ClosureReason::tryFrom($reason)
            ?? throw new BadRequestHttpException(\sprintf('Unknown closure reason "%s".', $reason));

        [$fullName, $avatar] = $this->authorSnapshot();
        $this->repository->saveClosureReason($this->contactId, $case, $fullName, $avatar);
        // The terminal banner and the follow-up thread show the reason live.
        $this->emit('contacts:changed');
    }

    public function getPendingCase(): ?NextStep
    {
        return '' !== $this->pendingStep ? NextStep::tryFrom($this->pendingStep) : null;
    }

    /** Staged toggle: nothing persists until confirmStep(). */
    #[LiveAction]
    public function pickStep(#[LiveArg] string $step): void
    {
        $this->ensureAdmin();

        $case = NextStep::tryFrom($step)
            ?? throw new BadRequestHttpException(\sprintf('Unknown next step "%s".', $step));

        $this->dateMissing = false;
        $this->channelMissing = false;
        // Clicking the selected chip deselects it.
        $this->pendingStep = $this->pendingStep === $case->value ? '' : $case->value;
    }

    /** Local clear of the staged date, no persistence. */
    #[LiveAction]
    public function clearPendingRecall(): void
    {
        $this->ensureAdmin();
        $this->recallAt = '';
        $this->dateMissing = false;
    }

    /** Drops the draft and restores the stored step and date. */
    #[LiveAction]
    public function cancelStep(): void
    {
        $this->ensureAdmin();

        $contact = $this->getContact();
        $this->pendingStep = $contact?->nextStep->value ?? '';
        $this->recallAt = $contact?->recallAt?->format('Y-m-d\TH:i') ?? '';
        $this->pendingChannel = $contact?->recontactChannel->value ?? '';
        $this->dateMissing = false;
        $this->channelMissing = false;
        $this->editingStep = false;
    }

    /**
     * The only place the draft persists and side effects fire (visio
     * invite sent or cancelled). A dated step without a valid future date
     * blocks the confirmation instead of half-saving.
     */
    #[LiveAction]
    public function confirmStep(): void
    {
        $this->ensureAdmin();
        $this->dateMissing = false;
        $this->channelMissing = false;

        $case = $this->getPendingCase();
        if ('' !== $this->pendingStep && null === $case) {
            throw new BadRequestHttpException(\sprintf('Unknown next step "%s".', $this->pendingStep));
        }

        // A dated step cannot be confirmed without a valid future date.
        $recallAt = null;
        if (null !== $case && $case->needsDate()) {
            $trimmed = trim($this->recallAt);
            // '!' zeroes the seconds: a slot is minute-precise everywhere.
            $recallAt = '' !== $trimmed ? (\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $trimmed) ?: null) : null;
            if (null === $recallAt) {
                // Unparseable input only: wipe it, nothing to fix in place.
                $this->recallAt = '';
                $this->dateMissing = true;

                return;
            }
            if ($recallAt < new \DateTimeImmutable()) {
                // Past slot: keep the typed value so the admin fixes the
                // digit instead of retyping everything.
                $this->dateMissing = true;

                return;
            }
        }

        // A recontact also needs its channel (email, WhatsApp, phone...).
        $channel = '' !== $this->pendingChannel ? RecontactChannel::tryFrom($this->pendingChannel) : null;
        if (NextStep::Recontact === $case && null === $channel) {
            $this->channelMissing = true;

            return;
        }

        $storedContact = $this->getContact();
        $stored = $storedContact?->nextStep;
        $storedRecallAt = $storedContact?->recallAt;
        if (NextStep::Visio === $stored && NextStep::Visio !== $case) {
            // The planned visio no longer applies: agenda cleaned, both
            // sides get the cancellation email.
            $entity = $this->repository->find($this->contactId);
            if (null !== $entity) {
                $this->visioMailer->cancel($entity);
            }
        }

        $this->repository->saveNextStep($this->contactId, $case);
        if (NextStep::Recontact === $case) {
            $this->repository->saveRecontactChannel($this->contactId, $channel);
        }
        // One coherent state only: the stored date always follows the step.
        // Dateless steps (quote preparation, other) and a cleared step purge
        // any stale recall left by a previous dated step.
        $this->repository->saveRecallAt($this->contactId, $recallAt);
        $this->recallAt = $recallAt?->format('Y-m-d\TH:i') ?? '';

        // Agenda mirror of the recontact: created or moved with the slot,
        // dropped when the step is no longer a dated recontact.
        $entity = $this->repository->find($this->contactId);
        if (null !== $entity) {
            $this->recallSync->apply($entity);
        }

        // Heads-up email to the prospect: planned slot + channel. AFTER
        // saveRecallAt: the mailer reads the stored date, sending earlier
        // silently dropped the notice on a first confirmation.
        if (NextStep::Recontact === $case && $this->notifyClient) {
            if (null !== $entity) {
                $this->recontactNotice->send($entity);
            }
            $this->notifyClient = false;
        }

        if (NextStep::Visio === $case) {
            $unchanged = NextStep::Visio === $stored
                && null !== $storedRecallAt
                && $storedRecallAt->format('Y-m-d H:i') === $recallAt?->format('Y-m-d H:i');
            if (!$unchanged) {
                // First plan: invites both sides (email + calendar event).
                // New date on an existing visio: event patched in place,
                // "rescheduled" emails. Re-confirming as-is sends nothing.
                $entity = $this->repository->find($this->contactId);
                if (null !== $entity) {
                    $this->visioMailer->send($entity, rescheduled: NextStep::Visio === $stored && null !== $storedRecallAt);
                }
            }
        }

        // Follow-up thread trace: what was decided, by whom, with the
        // slot and channel. Visio events are recorded by the mailer.
        if (NextStep::Visio !== $case && ($stored !== $case || null !== $recallAt)) {
            [$fullName, $avatar] = $this->authorSnapshot();
            $detailParts = [];
            if (null !== $recallAt) {
                $detailParts[] = $recallAt->format('d.m.Y H\hi');
            }
            if (NextStep::Recontact === $case && null !== $channel) {
                $detailParts[] = $this->translator->trans($channel->labelKey());
            }
            $entity = $this->repository->find($this->contactId);
            if (null !== $entity) {
                $this->events->recordKind(
                    $entity,
                    null === $case ? 'step_cleared' : 'step_'.$case->value,
                    [] !== $detailParts ? implode(' · ', $detailParts) : null,
                    $fullName,
                    $avatar,
                );
            }
        }

        $this->editingStep = false;
        $this->emit('contacts:changed');
    }

    /**
     * The visio happened: trace it, clear the completed step and open the
     * editor so the admin picks what comes next. The past calendar event
     * stays in the agendas (it is history, not noise).
     */
    #[LiveAction]
    public function visioDone(): void
    {
        $this->ensureAdmin();

        $entity = $this->repository->find($this->contactId);
        if (null === $entity || NextStep::Visio !== $entity->getNextStep()) {
            return;
        }

        [$fullName, $avatar] = $this->authorSnapshot();
        $this->events->recordKind($entity, 'visio_done', $entity->getRecallAt()?->format('d.m.Y H\hi'), $fullName, $avatar);
        $this->repository->saveNextStep($this->contactId, null);
        $this->repository->saveRecallAt($this->contactId, null);
        $entity->setVisioEventId(null)->setVisioMeetLink(null);

        $this->pendingStep = '';
        $this->recallAt = '';
        $this->dateMissing = false;
        $this->channelMissing = false;
        $this->editingStep = true;

        $this->emit('contacts:changed');
    }

    /**
     * The prospect did not show up: trace it and reopen the editor with
     * the visio preselected and an empty date, ready to replan.
     */
    #[LiveAction]
    public function visioNoShow(): void
    {
        $this->ensureAdmin();

        $entity = $this->repository->find($this->contactId);
        if (null === $entity || NextStep::Visio !== $entity->getNextStep()) {
            return;
        }

        [$fullName, $avatar] = $this->authorSnapshot();
        $this->events->recordKind($entity, 'visio_noshow', $entity->getRecallAt()?->format('d.m.Y H\hi'), $fullName, $avatar);

        $this->pendingStep = NextStep::Visio->value;
        $this->recallAt = '';
        $this->dateMissing = false;
        $this->channelMissing = false;
        $this->editingStep = true;

        $this->emit('contacts:changed');
    }

    /**
     * Cancels the planned step outright instead of editing it: a visio is
     * withdrawn from both agendas with its cancellation emails, a recall
     * loses its agenda mirror. The lead ends up with no next step and the
     * editor open, so the pipeline never stalls on an empty state.
     * Only dated commitments (visio, recontact) can be cancelled.
     */
    #[LiveAction]
    public function dropStep(): void
    {
        $this->ensureAdmin();

        $entity = $this->repository->find($this->contactId);
        $stored = $entity?->getNextStep();
        if (null === $entity || !\in_array($stored, [NextStep::Visio, NextStep::Recontact], true)) {
            return;
        }

        if (NextStep::Visio === $stored) {
            // Reads the step and date, so it must run before they are
            // cleared. Records the visio_cancelled event itself.
            $this->visioMailer->cancel($entity);
        } else {
            [$fullName, $avatar] = $this->authorSnapshot();
            $this->events->recordKind($entity, 'step_cancelled', $entity->getRecallAt()?->format('d.m.Y H\hi'), $fullName, $avatar);
        }

        $this->repository->saveNextStep($this->contactId, null);
        $this->repository->saveRecallAt($this->contactId, null);
        $this->repository->saveRecontactChannel($this->contactId, null);

        // Drops the recall mirror from the agent's agenda (no-op otherwise).
        $entity = $this->repository->find($this->contactId);
        if (null !== $entity) {
            $this->recallSync->apply($entity);
        }

        $this->pendingStep = '';
        $this->recallAt = '';
        $this->pendingChannel = '';
        $this->dateMissing = false;
        $this->channelMissing = false;
        $this->editingStep = true;

        $this->emit('contacts:changed');
    }

    #[LiveAction]
    public function editStep(): void
    {
        $this->ensureAdmin();

        // Prefill the draft with the stored state.
        $contact = $this->getContact();
        $this->pendingStep = $contact?->nextStep->value ?? '';
        $this->recallAt = $contact?->recallAt?->format('Y-m-d\TH:i') ?? '';
        $this->pendingChannel = $contact?->recontactChannel->value ?? '';
        $this->dateMissing = false;
        $this->channelMissing = false;
        $this->editingStep = true;
    }

    #[LiveAction]
    public function change(#[LiveArg] string $status): void
    {
        $this->ensureAdmin();

        $newStatus = ContactStatus::tryFrom($status)
            ?? throw new BadRequestHttpException(\sprintf('Unknown contact status "%s".', $status));

        // Planned-visio side effects (cancel + notify, keep on
        // conversion): shared matrix with the list quick actions.
        $entity = $this->repository->find($this->contactId);
        if (null !== $entity) {
            $this->visioMailer->onStatusChange($entity, $newStatus);
            // The recall mirror follows the same matrix, silently: kept
            // through conversion, dropped on close or rollback to new.
            if (\in_array($newStatus, [ContactStatus::Closed, ContactStatus::New], true)) {
                $this->recallSync->clear($entity);
            }
        }
        [$fullName, $avatar] = $this->authorSnapshot();
        $firstTreatment = $this->repository->updateStatus($this->contactId, $newStatus, $fullName, $avatar);
        if ($firstTreatment) {
            // One-shot nudge: the LeadQuality card asks for a rating.
            $this->emit('lead:first-treated');
        }
        // A fresh status starts from a clean editor state and a clean draft.
        $this->editingStep = false;
        $this->pendingStep = '';
        $this->pendingChannel = '';
        $this->channelMissing = false;
        $this->notifyClient = false;
        $this->recallAt = '';
        $this->dateMissing = false;
        $this->emit('contacts:changed');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function authorSnapshot(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [null, null];
        }

        return [
            trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: $user->getEmail(),
            $user->getAvatarFilename(),
        ];
    }

    /**
     * Assignable team members, as scalar rows (never entities in templates).
     * Same principle as ContactFollowUp: chips to pick, X to unassign.
     *
     * @return list<array{id: int, name: string, avatar: ?string, me: bool}>
     */
    public function getAssignees(): array
    {
        $currentUser = $this->security->getUser();
        $currentId = $currentUser instanceof User ? (int) $currentUser->getId() : 0;

        $rows = array_map(
            static fn ($user): array => [
                'id' => (int) $user->getId(),
                'name' => trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail(),
                'avatar' => $user->getAvatarFilename(),
                'me' => (int) $user->getId() === $currentId,
            ],
            $this->users->findAdmins(),
        );

        // Self-assignment is the most common gesture: "Moi" comes first.
        usort($rows, static fn (array $a, array $b): int => $b['me'] <=> $a['me']);

        return $rows;
    }

    #[LiveAction]
    public function assign(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $user = 0 !== $id ? $this->users->find($id) : null;
        if (0 !== $id && null === $user) {
            throw new BadRequestHttpException('Unknown assignee.');
        }
        // Only members of the assignable pool: a forged id must not park
        // a lead on an account outside the contacts section.
        if (null !== $user && !\in_array($id, array_column($this->getAssignees(), 'id'), true)) {
            throw new BadRequestHttpException('Assignee outside the contacts team.');
        }

        $this->repository->assign($this->contactId, $user);

        // A planned visio follows the lead: the calendar event's attendee
        // list swaps to the new assignee.
        $entity = $this->repository->find($this->contactId);
        if (null !== $entity && null !== $entity->getVisioEventId()) {
            $this->visioMailer->refreshAttendees($entity);
        }
        // Same swap for a mirrored recall event.
        if (null !== $entity && null !== $entity->getRecallEventId()) {
            $this->recallSync->apply($entity);
        }

        $this->emit('contacts:changed');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
