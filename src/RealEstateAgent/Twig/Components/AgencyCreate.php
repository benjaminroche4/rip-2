<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Form\AgencyType;
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
 * "New agency" modal on the admin agents section: a single name field.
 * A standalone agency can be registered before it has any agent; the name
 * is unique (case-insensitively), so a duplicate is refused with a field
 * error. Redirects back to the list once created.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgencyCreate', template: 'components/RealEstateAgent/AgencyCreate.html.twig')]
final class AgencyCreate extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Agency $agency = null;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $open = false;

    public function __construct(
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
    ) {
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

        /** @var Agency $agency */
        $agency = $this->getForm()->getData();
        $name = trim((string) $agency->getName());

        // Explicit creation refuses a duplicate (unlike the agent modal's
        // silent find-or-create): "Foncia" must not become a second row.
        if (null !== $this->agencies->findByName($name)) {
            $this->getForm()->get('name')->addError(
                new FormError($translator->trans('admin.agencies.create.name.exists')),
            );
            throw new UnprocessableEntityHttpException('An agency with this name already exists.');
        }

        $agency->setName($name);
        $agency->setCreatedAt(new \DateTimeImmutable());
        $em->persist($agency);
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
