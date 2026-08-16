<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\Auth\Service\AvatarDownloader;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentDetail;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Domain\ProfessionalCard;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Agent identity card on the detail page, editable in place (direct pencil,
 * same flow as the lead identity card) with a guarded profile deletion.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentDetails', template: 'components/RealEstateAgent/AgentDetails.html.twig')]
final class AgentDetails extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $agentId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $editing = false;

    #[LiveProp]
    public bool $confirmingDelete = false;

    #[LiveProp]
    public bool $confirmingDeactivate = false;

    /** Validation errors keyed by field, shown under the inputs. */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $phone = '';

    /** Free text: a known name reuses the agency, a new one creates it, empty = independent. */
    #[LiveProp(writable: true)]
    public string $agencyName = '';

    #[LiveProp(writable: true)]
    public string $note = '';

    /** @var list<string> chip-toggled, whitelisted against AgentSpecialty */
    #[LiveProp]
    public array $specialties = [];

    /** @var list<string> cartes loi Hoguet (T, G, S), chip-toggled, whitelisted against ProfessionalCard */
    #[LiveProp]
    public array $professionalCards = [];

    /** '' = none; chip-toggled, whitelisted against AgencyPosition. */
    #[LiveProp]
    public string $position = '';

    public function __construct(
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly Security $security,
        private readonly RealEstateAgentRepository $agents,
        private readonly AgencyRepository $agencies,
        private readonly \App\Visit\Repository\VisitRepository $visits,
        private readonly \Symfony\Component\Clock\ClockInterface $clock,
        private readonly AvatarDownloader $avatars,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function mount(int $agentId): void
    {
        $this->ensureAdmin();
        $this->agentId = $agentId;
        $this->prefill();
    }

    public function getAgent(): ?AgentDetail
    {
        return $this->agents->findDetail($this->agentId);
    }

    /**
     * Existing agency names for the edit datalist.
     *
     * @return list<string>
     */
    public function getAgencyNames(): array
    {
        return $this->agencies->findAllNames();
    }

    /**
     * Visits booked with this directory agent, split like the dossier visit
     * module: upcoming ones first (soonest on top), then past ones (most
     * recent on top). "Past" starts at the previous midnight.
     *
     * @return array{upcoming: list<\App\Visit\Domain\VisitSummary>, past: list<\App\Visit\Domain\VisitSummary>}
     */
    public function getAgentVisits(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $upcoming = [];
        $past = [];
        foreach ($this->visits->findByRealEstateAgentSummaries($this->agentId) as $summary) {
            if ($summary->scheduledAt >= $today) {
                $upcoming[] = $summary;
            } else {
                $past[] = $summary;
            }
        }

        return ['upcoming' => $upcoming, 'past' => array_reverse($past)];
    }

    #[LiveAction]
    public function startEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = true;
    }

    #[LiveAction]
    public function cancelEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = false;
        $this->errors = [];
    }

    /** Multi-select chips with toggle-off, like the creation form. */
    #[LiveAction]
    public function toggleSpecialty(#[LiveArg] string $specialty): void
    {
        $this->ensureAdmin();

        if (null === AgentSpecialty::tryFrom($specialty)) {
            throw new BadRequestHttpException(\sprintf('Unknown specialty "%s".', $specialty));
        }
        $this->specialties = \in_array($specialty, $this->specialties, true)
            ? array_values(array_diff($this->specialties, [$specialty]))
            : [...$this->specialties, $specialty];
    }

    /** Multi-select chips with toggle-off, same gesture as the specialties. */
    #[LiveAction]
    public function toggleCard(#[LiveArg] string $card): void
    {
        $this->ensureAdmin();

        if (null === ProfessionalCard::tryFrom($card)) {
            throw new BadRequestHttpException(\sprintf('Unknown professional card "%s".', $card));
        }
        $this->professionalCards = \in_array($card, $this->professionalCards, true)
            ? array_values(array_diff($this->professionalCards, [$card]))
            : [...$this->professionalCards, $card];
    }

    /** Single-select chips with toggle-off (a radio cannot untoggle itself). */
    #[LiveAction]
    public function togglePosition(#[LiveArg] string $position): void
    {
        $this->ensureAdmin();

        if (null === AgencyPosition::tryFrom($position)) {
            throw new BadRequestHttpException(\sprintf('Unknown position "%s".', $position));
        }
        $this->position = $this->position === $position ? '' : $position;
    }

    #[LiveAction]
    public function saveDetails(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->ensureAdmin();

        $this->errors = [];
        if ('' === trim($this->firstName)) {
            $this->errors['firstName'] = 'admin.contacts.edit.required';
        }
        if ('' === trim($this->lastName)) {
            $this->errors['lastName'] = 'admin.contacts.edit.required';
        }
        if ('' !== trim($this->email) && false === filter_var(trim($this->email), \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'admin.contacts.edit.invalidEmail';
        }
        if ([] !== $this->errors) {
            return;
        }

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');

        $agencyName = trim($this->agencyName);
        $agency = '' !== $agencyName ? $this->agencies->findOrCreate($agencyName) : null;

        $agent
            ->setFirstName(trim($this->firstName))
            ->setLastName(trim($this->lastName))
            ->setEmail('' !== trim($this->email) ? trim($this->email) : null)
            ->setPhone('' !== trim($this->phone) ? trim($this->phone) : null)
            ->setAgency($agency)
            ->setSpecialties(array_map(AgentSpecialty::from(...), $this->specialties))
            ->setProfessionalCards(array_map(ProfessionalCard::from(...), $this->professionalCards))
            // The position only makes sense inside an agency.
            ->setPosition(null !== $agency && '' !== $this->position ? AgencyPosition::from($this->position) : null)
            ->setNote('' !== trim($this->note) ? trim($this->note) : null)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setUpdatedByName($this->currentStaffName());
        $em->flush();

        // Nouvelle photo optionnelle, envoyée avec l'action (input file
        // "photo") : normalisée WebP 256x256 et stockée sous agents/<id>/.
        // Un fichier illisible n'empêche jamais l'enregistrement.
        $upload = $this->requestStack->getCurrentRequest()?->files->get('photo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agent->getId(), 'agents', 'avatar') : null;
            if (null !== $stored) {
                if (null !== $agent->getAvatarFilename()) {
                    $this->avatars->delete($agent->getAvatarFilename());
                }
                $agent->setAvatarFilename($stored);
                $em->flush();
            }
        }

        $this->prefill();
        $this->editing = false;
        // The page chrome (title, breadcrumb) lives outside the component:
        // a Turbo morph refresh picks up the new name.
        $this->dispatchBrowserEvent('agent-identity:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.toast.saved')]);
    }

    /** The switch asks first: turning the profile off is confirmed in a modal. */
    #[LiveAction]
    public function askDeactivate(): void
    {
        $this->ensureAdmin();
        $this->confirmingDeactivate = true;
    }

    #[LiveAction]
    public function cancelDeactivate(): void
    {
        $this->ensureAdmin();
        $this->confirmingDeactivate = false;
    }

    /** Confirmed in the modal: the agent leaves the pickers, nothing is deleted. */
    #[LiveAction]
    public function deactivateAgent(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setDeactivatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->securityLogger->notice('Real-estate agent deactivated from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $this->confirmingDeactivate = false;
    }

    /** Direct action, no modal: reactivating is harmless. */
    #[LiveAction]
    public function reactivateAgent(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setDeactivatedAt(null);
        $em->flush();

        $this->securityLogger->notice('Real-estate agent reactivated from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }

    #[LiveAction]
    public function askDelete(): void
    {
        $this->ensureAdmin();
        // Suppression réservée aux administrateurs (le menu la masque déjà
        // pour le staff de section, ceci couvre l'appel direct au endpoint).
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Deletion is admin-only.');
        }
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->ensureAdmin();
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function deleteAgent(EntityManagerInterface $em): RedirectResponse
    {
        $this->ensureAdmin();
        // Suppression réservée aux administrateurs (le menu la masque déjà
        // pour le staff de section, ceci couvre l'appel direct au endpoint).
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Deletion is admin-only.');
        }

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');

        // Stored photo removed with the profile (nothing must outlive it).
        if (null !== $agent->getAvatarFilename()) {
            $this->avatars->delete($agent->getAvatarFilename());
        }

        $this->securityLogger->warning('Real-estate agent profile deleted from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $em->remove($agent);
        $em->flush();

        try {
            $this->addFlash('success', 'admin.toast.agentDeleted');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function prefill(): void
    {
        $agent = $this->getAgent();
        $this->firstName = $agent->firstName ?? '';
        $this->lastName = $agent->lastName ?? '';
        $this->email = $agent->email ?? '';
        $this->phone = $agent->phone ?? '';
        $this->agencyName = $agent->agencyName ?? '';
        $this->note = $agent->note ?? '';
        $this->specialties = array_map(static fn (AgentSpecialty $s): string => $s->value, $agent->specialties ?? []);
        $this->professionalCards = array_map(static fn (ProfessionalCard $c): string => $c->value, $agent->professionalCards ?? []);
        $this->position = $agent->position->value ?? '';
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }

    /** Instantané du staff courant (nom, sinon email) pour la traçabilité. */
    private function currentStaffName(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof \App\Auth\Entity\User) {
            return null;
        }
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : (string) $user->getEmail();
    }
}
