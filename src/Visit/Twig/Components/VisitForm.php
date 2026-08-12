<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Visit\Entity\Visit;
use App\Visit\Form\VisitType;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Quick visit booking form: only the essential fields up front, the rest
 * behind a "detail mode" toggle. Address coordinates come from the Places
 * autocomplete (setLocation); creation falls back to server geocoding when
 * the address was typed by hand.
 */
#[AsLiveComponent(name: 'Visit:VisitForm', template: 'components/Visit/VisitForm.html.twig')]
final class VisitForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Visit $visit = null;

    #[LiveProp]
    public string $adminPrefix = '';

    /** Coordinates of the last successfully located address. */
    #[LiveProp]
    public ?float $previewLat = null;

    #[LiveProp]
    public ?float $previewLng = null;

    /** Address the preview coordinates belong to — avoids re-geocoding. */
    #[LiveProp]
    public string $locatedAddress = '';

    /** Detail mode: shows assignee, agent, listing link, presence and note. */
    #[LiveProp]
    public bool $detailed = false;

    public function __construct(
        private readonly Security $security,
        private readonly \App\Auth\Repository\UserRepository $users,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly \App\Visit\Service\VisitNumberGenerator $numbers,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->visit ??= new Visit();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(VisitType::class, $this->visit ??= new Visit());
    }

    /**
     * Fired when a suggestion of the places-autocomplete dropdown is picked
     * (same Places flow as the public listing form): the browser already has
     * the coordinates, no server-side geocoding involved.
     */
    #[LiveAction]
    public function setLocation(#[LiveArg] ?float $lat = null, #[LiveArg] ?float $lng = null): void
    {
        $this->ensureAdmin();

        if (null === $lat || null === $lng) {
            return;
        }

        $this->previewLat = $lat;
        $this->previewLng = $lng;
        // The autocomplete wrote the formatted address into the input right
        // before triggering this action, so the current form value is the
        // address these coordinates belong to.
        $this->locatedAddress = trim((string) ($this->formValues['address'] ?? ''));
    }

    /** Chevron toggle of the optional fields; kept out of set*() naming. */
    #[LiveAction]
    public function toggleDetails(): void
    {
        $this->ensureAdmin();

        $this->detailed = !$this->detailed;
    }

    /**
     * Visit-agent chips of the "performed by" picker.
     *
     * @return list<array{id: int, name: string, avatar: ?string}>
     */
    public function getAssigneeChoices(): array
    {
        return array_map(
            static fn (\App\Auth\Entity\User $user): array => [
                'id' => (int) $user->getId(),
                'name' => trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail(),
                'avatar' => $user->getAvatarFilename(),
            ],
            $this->users->findVisitAgents(),
        );
    }

    /** Chip toggle: picking the selected agent deselects them. */
    #[LiveAction]
    public function pickAssignee(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $current = (int) ($this->formValues['assignee'] ?? 0);
        $this->formValues['assignee'] = $current === $id ? '' : (string) $id;
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, AddressGeocoder $geocoder): RedirectResponse
    {
        $this->ensureAdmin();

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors.
        $this->submitForm();

        /** @var Visit $visit */
        $visit = $this->getForm()->getData();

        // Reuse the coordinates already located for the preview when they
        // match the submitted address; geocode once otherwise.
        $address = trim((string) $visit->getAddress());
        if (null !== $this->previewLat && null !== $this->previewLng && $address === $this->locatedAddress) {
            $visit->setLatitude($this->previewLat);
            $visit->setLongitude($this->previewLng);
        } else {
            $point = $geocoder->geocode($address);
            if (null !== $point) {
                $visit->setLatitude($point->latitude);
                $visit->setLongitude($point->longitude);
            }
        }

        // Property visits only happen in Île-de-France: coordinates
        // checked against the region's bounding box when available,
        // postal-code fallback otherwise.
        if (!$this->isInIleDeFrance($visit)) {
            $this->getForm()->get('address')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.address.idf'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Address outside Île-de-France.');
        }

        $visit->setCreatedAt(new \DateTimeImmutable());
        $visit->setReference($this->numbers->reference());
        $user = $this->security->getUser();
        if ($user instanceof \App\Auth\Entity\User) {
            $visit->setBookedBy($user);
        }
        $em->persist($visit);
        $em->flush();

        return $this->redirectToRoute('admin_visits', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function isInIleDeFrance(Visit $visit): bool
    {
        $lat = $visit->getLatitude();
        $lng = $visit->getLongitude();
        if (null !== $lat && null !== $lng) {
            return $lat >= 48.12 && $lat <= 49.242 && $lng >= 1.446 && $lng <= 3.559;
        }

        return 1 === preg_match('/\b(75|77|78|91|92|93|94|95)\d{3}\b/', (string) $visit->getAddress());
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
