<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Auth\Repository\UserRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use App\Visit\Domain\VisitType;
use App\Visit\Entity\Visit;
use App\Visit\Repository\VisitRepository;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Inline editor of the visit detail card, dossier-style: fields start
 * locked behind the padlock (anti-missclick), then every change autosaves
 * on its own. The dossier attachment stays read-only (rare enough to
 * recreate the visit); chips persist immediately, text fields save on
 * change with per-field errors.
 */
#[AsLiveComponent(name: 'Visit:VisitDetails', template: 'components/Visit/VisitDetails.html.twig')]
final class VisitDetails
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    private const TIMEZONE = 'Europe/Paris';

    #[LiveProp]
    public int $visitId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    /** Anti-missclick shield, same gesture as the dossier cards. */
    #[LiveProp]
    public bool $locked = true;

    #[LiveProp(writable: true)]
    public string $scheduledAt = '';

    #[LiveProp(writable: true)]
    public string $address = '';

    #[LiveProp(writable: true)]
    public string $listingUrl = '';

    #[LiveProp(writable: true)]
    public string $durationMinutes = '30';

    #[LiveProp(writable: true)]
    public string $note = '';

    /** Agent immobilier sélectionné ('0' = aucun), autosauvé au changement. */
    #[LiveProp(writable: true, onUpdated: 'onAgentUpdated')]
    public string $agentId = '0';

    /** field name => translation key. */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly VisitRepository $visits,
        private readonly UserRepository $users,
        private readonly RealEstateAgentRepository $agents,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly AddressGeocoder $geocoder,
    ) {
    }

    public function mount(int $visitId): void
    {
        $this->ensureAdmin();
        $this->visitId = $visitId;
        $this->prefill();
    }

    public function getVisit(): ?\App\Visit\Domain\VisitSummary
    {
        return $this->visits->findOneSummary($this->visitId);
    }

    /**
     * @return list<array{id: int, name: string, avatar: ?string}>
     */
    public function getAssigneeChoices(): array
    {
        return array_map(
            static fn ($user): array => [
                'id' => (int) $user->getId(),
                'name' => trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail(),
                'avatar' => $user->getAvatarFilename(),
            ],
            $this->users->findVisitAgents(),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getAgentChoices(): array
    {
        return array_map(
            static function ($agent): array {
                $name = trim($agent->getFirstName().' '.$agent->getLastName());
                $agency = $agent->getAgency()?->getName();

                return ['id' => (int) $agent->getId(), 'name' => null !== $agency ? $name.' ('.$agency.')' : $name];
            },
            $this->agents->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']),
        );
    }

    /**
     * Autres visites déjà prévues sur le même bien (même adresse ou même lien
     * d'annonce), la visite courante exclue.
     *
     * @return list<\App\Visit\Domain\VisitSummary>
     */
    public function getMatchingVisits(): array
    {
        $address = trim($this->address);
        if (mb_strlen($address) < 5) {
            $address = '';
        }

        return $this->visits->findMatchingSummaries($address, trim($this->listingUrl), $this->visitId);
    }

    #[LiveAction]
    public function toggleLock(): void
    {
        $this->ensureAdmin();
        $this->locked = !$this->locked;
        if ($this->locked) {
            // Relocking drops any unsaved draft and stale errors.
            $this->errors = [];
            $this->prefill();
        }
    }

    #[LiveAction]
    public function chooseType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $case = VisitType::tryFrom($type)
            ?? throw new BadRequestHttpException(\sprintf('Unknown visit type "%s".', $type));
        $this->entity()->setType($case);
        $this->em->flush();
    }

    #[LiveAction]
    public function toggleClientPresent(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $visit = $this->entity();
        $visit->setClientPresent(!$visit->isClientPresent());
        $this->em->flush();
    }

    #[LiveAction]
    public function pickAssignee(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $visit = $this->entity();
        if (0 !== $id && !\in_array($id, array_column($this->getAssigneeChoices(), 'id'), true)) {
            throw new BadRequestHttpException('Assignee outside the visit-agents pool.');
        }
        $current = (int) $visit->getAssignee()?->getId();
        $visit->setAssignee($current === $id || 0 === $id ? null : $this->users->find($id));
        $this->em->flush();
    }

    public function onAgentUpdated(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $visit = $this->entity();
        $id = (int) $this->agentId;
        if (0 === $id) {
            $visit->setAgent(null);
        } else {
            $agent = $this->agents->find($id)
                ?? throw new BadRequestHttpException('Unknown real-estate agent.');
            $visit->setAgent($agent);
        }
        $this->em->flush();
    }

    /** Autosave of the text/date fields, per-field errors, geocode on move. */
    #[LiveAction]
    public function save(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $this->errors = [];
        $visit = $this->entity();

        // Date : future only when it actually moved (editing the address of
        // a past visit must not be blocked by its old date).
        $raw = trim($this->scheduledAt);
        $scheduledAt = '' !== $raw
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new \DateTimeZone(self::TIMEZONE)) ?: null)
            : null;
        if (null === $scheduledAt) {
            $this->errors['scheduledAt'] = 'admin.visits.create.scheduledAt.notNull';
        } elseif (
            $scheduledAt->format('Y-m-d H:i') !== $visit->getScheduledAt()?->format('Y-m-d H:i')
            && $scheduledAt < new \DateTimeImmutable()
        ) {
            $this->errors['scheduledAt'] = 'admin.visits.create.scheduledAt.past';
        }

        $address = trim($this->address);
        $addressChanged = $address !== (string) $visit->getAddress();
        if ('' === $address) {
            $this->errors['address'] = 'admin.visits.create.address.notBlank';
        }

        $listingUrl = trim($this->listingUrl);
        if ('' !== $listingUrl && !filter_var($listingUrl, \FILTER_VALIDATE_URL)) {
            $this->errors['listingUrl'] = 'admin.visits.create.listingUrl.invalid';
        }

        $duration = (int) $this->durationMinutes;
        if (!\in_array($duration, [15, 30, 45, 60], true)) {
            $this->errors['durationMinutes'] = 'admin.visits.create.duration.invalid';
        }

        $point = null;
        if ($addressChanged && '' !== $address) {
            $point = $this->geocoder->geocode($address);
            $latitude = $point?->latitude;
            $longitude = $point?->longitude;
            // Île-de-France only: coordinates first, postal-code fallback.
            $inIdf = null !== $latitude && null !== $longitude
                ? ($latitude >= 48.12 && $latitude <= 49.242 && $longitude >= 1.446 && $longitude <= 3.559)
                : 1 === preg_match('/\b(75|77|78|91|92|93|94|95)\d{3}\b/', $address);
            if (!$inIdf) {
                $this->errors['address'] = 'admin.visits.create.address.idf';
            }
        }

        if ([] !== $this->errors) {
            return;
        }

        \assert(null !== $scheduledAt);
        $visit->setScheduledAt($scheduledAt)
            ->setAddress($address)
            ->setListingUrl('' !== $listingUrl ? $listingUrl : null)
            ->setDurationMinutes($duration)
            ->setNote('' !== trim($this->note) ? trim($this->note) : null);
        if ($addressChanged) {
            $visit->setLatitude($point?->latitude)
                ->setLongitude($point?->longitude);
        }
        $this->em->flush();

        // The page chrome (title, header, sticky map) lives outside the
        // component: morph-refresh it.
        $this->dispatchBrowserEvent('visit-details:changed');
    }

    private function entity(): Visit
    {
        return $this->visits->find($this->visitId)
            ?? throw new BadRequestHttpException('Unknown visit.');
    }

    private function prefill(): void
    {
        $visit = $this->visits->find($this->visitId);
        if (null === $visit) {
            return;
        }

        $this->scheduledAt = $visit->getScheduledAt()?->format('Y-m-d\TH:i') ?? '';
        $this->agentId = (string) ((int) $visit->getAgent()?->getId());
        $this->address = (string) $visit->getAddress();
        $this->listingUrl = (string) $visit->getListingUrl();
        $this->durationMinutes = (string) $visit->getDurationMinutes();
        $this->note = (string) $visit->getNote();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
