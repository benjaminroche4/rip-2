<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Form\AgencyType;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\BrandRepository;
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
 * "New agency" form on its dedicated admin page: a single name field.
 * A standalone agency can be registered before it has any agent; the name
 * is unique (case-insensitively), so a duplicate is refused with a field
 * error. Redirects back to the list once created.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgencyCreate', template: 'components/RealEstateAgent/AgencyCreate.html.twig')]
final class AgencyCreate extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Agency $agency = null;

    #[LiveProp]
    public string $adminPrefix = '';

    public function __construct(
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Auth\Service\AvatarDownloader $avatars,
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
        private readonly BrandRepository $brands,
    ) {
    }

    /**
     * Existing brand names for the autocomplete datalist: typing a known
     * name reuses the brand, a new name creates it, empty = no brand.
     *
     * @return list<string>
     */
    public function getBrandNames(): array
    {
        return $this->brands->findAllNames();
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->agency ??= new Agency();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(AgencyType::class, $this->agency ??= new Agency());
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, TranslatorInterface $translator): RedirectResponse
    {
        $this->ensureAdmin();

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors, form stays in place.
        $this->submitForm();

        /** @var Agency $agency */
        $agency = $this->getForm()->getData();
        $name = trim((string) $agency->getName());

        // Explicit creation refuses a duplicate (unlike the agent form's
        // silent find-or-create): "Foncia" must not become a second row.
        if (null !== $this->agencies->findByName($name)) {
            $this->getForm()->get('name')->addError(
                new FormError($translator->trans('admin.agencies.create.name.exists')),
            );
            throw new UnprocessableEntityHttpException('An agency with this name already exists.');
        }

        // Free-text brand resolved to an entity: a known name is reused
        // case-insensitively, a new name is created, empty = no brand.
        $brandName = trim((string) $this->getForm()->get('brandName')->getData());
        $agency->setBrand('' !== $brandName ? $this->brands->findOrCreate($brandName) : null);

        $agency->setName($name);
        $agency->setCreatedAt(new \DateTimeImmutable());
        $em->persist($agency);
        $em->flush();

        // Logo optionnel, envoyé avec l'action (input file "logo") :
        // normalisé WebP 256x256 et stocké sous agencies/<id>/logo/. Un
        // fichier illisible n'empêche jamais la création (best-effort).
        $upload = $this->requestStack->getCurrentRequest()?->files->get('logo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agency->getId(), 'agencies', 'logo') : null;
            if (null !== $stored) {
                $agency->setLogoFilename($stored);
                $em->flush();
            }
        }

        try {
            $this->addFlash('success', 'admin.toast.agencyCreated');
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
