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
        private readonly \Doctrine\Persistence\ManagerRegistry $managerRegistry,
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

        // Téléphone normalisé E.164 côté serveur (même canon que les leads);
        // un numéro invalide est refusé avec une erreur de champ.
        if (null !== $agency->getPhone()) {
            $e164 = \App\Shared\Phone\PhoneNumberNormalizer::toE164($agency->getPhone());
            if (null === $e164) {
                $this->getForm()->get('phone')->addError(
                    new FormError($translator->trans('admin.contacts.edit.invalidPhone')),
                );
                throw new UnprocessableEntityHttpException('Invalid phone number.');
            }
            $agency->setPhone($e164);
        }
        // Instantané du créateur (nom + photo de profil) : traçabilité de
        // fiche, robuste même si le compte staff disparaît.
        $creator = $this->security->getUser();
        if ($creator instanceof \App\Auth\Entity\User) {
            $fullName = trim(($creator->getFirstName() ?? '').' '.($creator->getLastName() ?? ''));
            $agency->setCreatedByName('' !== $fullName ? $fullName : (string) $creator->getEmail());
            $agency->setCreatedByAvatar($creator->getAvatarFilename());
        }
        // Référence aléatoire : re-tirée si elle existe déjà (sinon l'index
        // unique transformerait la collision en 500 à la création).
        $this->agencies->ensureUniqueReference($agency);
        $em->persist($agency);
        try {
            $em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Course entre deux créations simultanées du même nom : l'index
            // unique tranche; même erreur de champ que le pré-contrôle (le
            // manager fermé par l'exception est réinitialisé pour le re-rendu).
            $this->managerRegistry->resetManager();
            $this->getForm()->get('name')->addError(
                new FormError($translator->trans('admin.agencies.create.name.exists')),
            );
            throw new UnprocessableEntityHttpException('An agency with this name already exists.');
        }

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

        // Retour sur la vue Agences : la fiche fraîche doit être visible
        // immédiatement (deleteAgency fait de même).
        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
            'view' => 'agencies',
        ]);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
