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
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "New agent" modal on the admin agents list: identity plus optional agency
 * and contact details. Redirects back to the list once created so the fresh
 * agent shows up in alphabetical order.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentCreate', template: 'components/RealEstateAgent/AgentCreate.html.twig')]
final class AgentCreate extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?RealEstateAgent $agent = null;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $open = false;

    public function __construct(
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
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

    #[LiveAction]
    public function openModal(): void
    {
        $this->ensureAdmin();
        $this->open = true;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->ensureAdmin();
        $this->open = false;
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, TranslatorInterface $translator): ?RedirectResponse
    {
        $this->ensureAdmin();

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors, modal stays open.
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

        $agent->setCreatedAt(new \DateTimeImmutable());
        $em->persist($agent);
        $em->flush();

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
