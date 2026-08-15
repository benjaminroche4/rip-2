<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Form\DossierType;
use App\Dossier\Service\DossierDriveProvisioner;
use App\Dossier\Service\DossierNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * "New dossier" modal on the admin dossiers list: a name + up to 4 persons
 * (2 tenants, 2 follow-up requests), one of the tenants being tagged as the
 * primary contact ("locataire principal"). Redirects back to the list once
 * created so the fresh dossier shows up.
 */
#[AsLiveComponent(name: 'Dossier:DossierCreate', template: 'components/Dossier/DossierCreate.html.twig')]
final class DossierCreate extends AbstractController
{
    use DossiersSectionGuard;
    use DefaultActionTrait;
    use LiveCollectionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Dossier $dossier = null;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $open = false;

    /**
     * Form key of the person carrying the "locataire principal" tag. May go
     * stale after a removal or a role switch — getEffectivePrimaryKey() falls
     * back to the first tenant, which is also what create() persists.
     */
    #[LiveProp]
    public int $primaryKey = 0;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->dossier ??= $this->buildEmptyDossier();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            DossierType::class,
            $this->dossier ??= $this->buildEmptyDossier(),
        );
    }

    /**
     * Resolves the primary tag against the current form values: the chosen
     * key if it still points to a tenant, otherwise the first tenant, or -1
     * when no tenant exists yet (NotNull/tenantRequired will complain at
     * submit time).
     */
    public function getEffectivePrimaryKey(): int
    {
        $tenantKeys = [];
        foreach ($this->formValues['persons'] ?? [] as $key => $person) {
            if (DossierPersonRole::TENANT->value === ($person['role'] ?? null)) {
                $tenantKeys[] = (int) $key;
            }
        }

        if ([] === $tenantKeys) {
            return -1;
        }

        return \in_array($this->primaryKey, $tenantKeys, true) ? $this->primaryKey : $tenantKeys[0];
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

    /**
     * Wraps LiveCollectionTrait::addCollectionItem with the MAX_PERSONS
     * guard — the button disappears at 4, this protects the endpoint.
     */
    #[LiveAction]
    public function addPerson(PropertyAccessorInterface $propertyAccessor): void
    {
        $this->ensureAdmin();

        $persons = $propertyAccessor->getValue($this->formValues, '[persons]') ?? [];
        if (\count($persons) >= Dossier::MAX_PERSONS) {
            return;
        }

        $this->addCollectionItem($propertyAccessor, $this->getFormName().'[persons]');
    }

    #[LiveAction]
    public function removePerson(PropertyAccessorInterface $propertyAccessor, #[LiveArg] int $key): void
    {
        $this->ensureAdmin();

        $this->removeCollectionItem($propertyAccessor, $this->getFormName().'[persons]', $key);
    }

    /**
     * Moves the "locataire principal" tag onto another tenant.
     */
    #[LiveAction]
    public function choosePrimary(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();

        $person = $this->formValues['persons'][$key] ?? null;
        if (null === $person || DossierPersonRole::TENANT->value !== ($person['role'] ?? null)) {
            return;
        }

        $this->primaryKey = $key;
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, DossierNumberGenerator $numbers, DossierDriveProvisioner $drive): ?RedirectResponse
    {
        $this->ensureAdmin();

        // The name is derived, not typed: set it on the draft BEFORE the
        // submit so its NotBlank constraint passes (name is not a form field,
        // the submit leaves it untouched).
        $this->dossier?->setName($this->generateName());

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors, modal stays open.
        $this->submitForm();

        /** @var Dossier $draft */
        $draft = $this->getForm()->getData();

        // Tag the primary tenant. Validation guarantees at least one tenant,
        // so the effective key always resolves here. The phone arrives in
        // E.164 form: the phone-input controller (intl-tel-input, same widget
        // as the public contact form) normalises it client-side on submit.
        $primaryKey = $this->getEffectivePrimaryKey();
        foreach ($this->getForm()->get('persons') as $key => $child) {
            /** @var DossierPerson $person */
            $person = $child->getData();
            $person->setPrimaryContact((int) $key === $primaryKey);
        }

        // Repair position numbering — collection removals can leave holes.
        $i = 0;
        foreach ($draft->getPersons() as $person) {
            $person->setPosition($i++);
        }

        $draft->setReference($numbers->reference());
        $draft->setPairingCode($numbers->pairingCode());
        // A fresh code is armed: the deposit page refuses it 90 days after
        // the last email embedding it (each send re-arms).
        $draft->setPairingCodeSentAt(new \DateTimeImmutable());
        $draft->setCreatedAt(new \DateTimeImmutable());
        $em->persist($draft);
        $em->flush();

        // Best-effort: provision the dossier's Shared Drive folder now (no-op
        // when Drive is off) so staff can drop pieces immediately.
        $drive->ensureDossierFolder($draft);

        // Success lands as a toast on the dossier we redirect to. Guarded:
        // the component is also mounted straight from unit tests, no session.
        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', 'admin.dossiers.create.success');
        }

        try {
            $this->addFlash('success', 'admin.toast.dossierCreated');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_dossier_show', [
            'adminPrefix' => $this->adminPrefix,
            'reference' => $draft->getReference(),
        ]);
    }

    /**
     * Standard dossier name derived from the tenants' last names, primary
     * tenant first: "Dupont", or "Dupont & Martin" for a couple. Falls back
     * to any person's last name (a tenant-less dossier fails validation
     * anyway), then to a generic label, so the entity's NotBlank never
     * produces an invisible root-form error.
     */
    private function generateName(): string
    {
        $primaryKey = $this->getEffectivePrimaryKey();

        $tenantNames = [];
        $fallback = null;
        foreach ($this->formValues['persons'] ?? [] as $key => $person) {
            $lastName = trim((string) ($person['lastName'] ?? ''));
            if ('' === $lastName) {
                continue;
            }
            if (DossierPersonRole::TENANT->value === ($person['role'] ?? null)) {
                $tenantNames[(int) $key] = $lastName;
            } else {
                $fallback ??= $lastName;
            }
        }

        // Primary tenant leads the label.
        if (isset($tenantNames[$primaryKey])) {
            $tenantNames = [$primaryKey => $tenantNames[$primaryKey]] + $tenantNames;
        }

        $name = implode(' & ', $tenantNames) ?: $fallback ?? 'Dossier';

        return mb_substr($name, 0, 100);
    }

    /**
     * Fresh dossier preloaded with one tenant — a dossier always has a main
     * tenant, so the first card starts on that role (and carries the tag).
     */
    private function buildEmptyDossier(): Dossier
    {
        $person = new DossierPerson();
        $person->setRole(DossierPersonRole::TENANT);
        $person->setLanguage(ContactLanguage::FR);

        $dossier = new Dossier();
        $dossier->addPerson($person);

        return $dossier;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
