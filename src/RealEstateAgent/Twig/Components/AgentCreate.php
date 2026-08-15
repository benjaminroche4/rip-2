<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Form\RealEstateAgentType;
use App\RealEstateAgent\Repository\AgencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "New agent" form on its dedicated admin page: identity plus optional
 * agency and contact details. Redirects back to the list once created so
 * the fresh agent shows up in alphabetical order.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentCreate', template: 'components/RealEstateAgent/AgentCreate.html.twig')]
final class AgentCreate extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?RealEstateAgent $agent = null;

    #[LiveProp]
    public string $adminPrefix = '';

    public function __construct(
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Auth\Service\AvatarDownloader $avatars,
    ) {
    }

    /**
     * Existing agency names for the autocomplete datalist: typing a known
     * name reuses the agency, a new name creates it, empty = independent.
     *
     * @return list<string>
     */
    public function getAgencyNames(): array
    {
        return $this->agencies->findAllNames();
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->agent ??= new RealEstateAgent();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(RealEstateAgentType::class, $this->agent ??= new RealEstateAgent());
    }

    /**
     * Single-select chips with toggle-off: clicking the active position
     * chip clears it (a radio cannot untoggle itself natively).
     */
    #[LiveAction]
    public function togglePosition(#[LiveArg] string $position): void
    {
        $this->ensureAdmin();

        $current = $this->formValues['position'] ?? '';
        $this->formValues['position'] = $current === $position ? '' : $position;
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, TranslatorInterface $translator): ?RedirectResponse
    {
        $this->ensureAdmin();

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors, form stays in place.
        $this->submitForm();

        /** @var RealEstateAgent $agent */
        $agent = $this->getForm()->getData();

        // The agency name is only meaningful (and then required) when the
        // agent works for one; an independent agent ignores any leftover
        // value typed before switching the kind chip.
        $kind = (string) $this->getForm()->get('kind')->getData();
        $agencyName = trim((string) $this->getForm()->get('agencyName')->getData());
        if ('agency' === $kind && '' === $agencyName) {
            $this->getForm()->get('agencyName')->addError(
                new FormError($translator->trans('admin.agents.create.agency.notBlank')),
            );
            throw new UnprocessableEntityHttpException('The agency name is required for an agency agent.');
        }
        $agent->setAgency('agency' === $kind ? $this->agencies->findOrCreate($agencyName) : null);

        // The position only makes sense inside an agency: a leftover value
        // picked before switching the kind chip to independent is dropped.
        if ('agency' !== $kind) {
            $agent->setPosition(null);
        }

        $agent->setCreatedAt(new \DateTimeImmutable());
        $em->persist($agent);
        $em->flush();

        // Photo optionnelle, envoyée avec l'action (input file "photo") :
        // normalisée WebP 256x256 et stockée sous agents/<id>/avatar/. Un
        // fichier illisible n'empêche jamais la création (best-effort).
        $upload = $this->requestStack->getCurrentRequest()?->files->get('photo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agent->getId(), 'agents', 'avatar') : null;
            if (null !== $stored) {
                $agent->setAvatarFilename($stored);
                $em->flush();
            }
        }

        try {
            $this->addFlash('success', 'admin.toast.agentCreated');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
