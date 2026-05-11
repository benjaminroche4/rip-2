<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Admin\Form\DocumentRequestType;
use App\Admin\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * Multi-person bilingual document request form, rendered on
 * /admin/outils/documents/demande. Uses LiveCollectionTrait so the admin can
 * add/remove persons (1..5) without a full page reload.
 *
 * Submit pipeline (LiveAction `generate`):
 *   1. submitForm() — bubbles UnprocessableEntityHttpException on invalid input
 *   2. persist the DocumentRequest (cascade saves PersonRequest + M2M Document)
 *   3. dispatch browser event 'document-request:download' with the PDF URL,
 *      picked up by the download_trigger Stimulus controller on the form
 *   4. mount a fresh DocumentRequest so the form is clean for the next admin
 */
#[AsLiveComponent(
    name: 'Admin:DocumentRequestForm',
    template: 'components/Admin/DocumentRequestForm.html.twig',
)]
final class DocumentRequestForm extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;
    use ComponentToolsTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?DocumentRequest $request = null;

    #[LiveProp]
    public string $adminPrefix = '';

    /**
     * Visual position (0-based) of the person currently shown in the
     * sidebar's right panel. Bumped to the new last index when a person
     * is added, clamped after a removal so we don't point past the end.
     */
    #[LiveProp(writable: true)]
    public int $activePersonIndex = 0;

    public function __construct(
        private readonly Security $security,
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    /**
     * @return array<int, \App\Admin\Entity\Document>
     */
    public function getDocumentsById(): array
    {
        $byId = [];
        foreach ($this->documentRepository->findBy([], ['nameFr' => 'ASC']) as $doc) {
            $byId[$doc->getId()] = $doc;
        }

        return $byId;
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        if (null === $this->request) {
            $this->request = $this->buildEmptyRequest();
        }
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            DocumentRequestType::class,
            $this->request ??= $this->buildEmptyRequest(),
        );
    }

    /**
     * Wraps LiveCollectionTrait::addCollectionItem so we can also fast-
     * forward `activePersonIndex` to the freshly added entry. UX-wise:
     * clicking "+ Ajouter une personne" should jump you straight to the
     * new (empty) form, not leave you on the previously active one.
     */
    #[LiveAction]
    public function addPerson(PropertyAccessorInterface $propertyAccessor): void
    {
        $this->ensureAdmin();

        $this->addCollectionItem($propertyAccessor, $this->getFormName().'[persons]');

        $persons = $propertyAccessor->getValue($this->formValues, '[persons]') ?? [];
        // Active = visual position of the new last person (count - 1).
        $this->activePersonIndex = max(0, \count($persons) - 1);
    }

    /**
     * Wraps LiveCollectionTrait::removeCollectionItem and clamps the
     * active index so it never points past the (now shorter) list.
     */
    #[LiveAction]
    public function removePerson(PropertyAccessorInterface $propertyAccessor, #[LiveArg] int $index): void
    {
        $this->ensureAdmin();

        $this->removeCollectionItem($propertyAccessor, $this->getFormName().'[persons]', $index);

        $persons = $propertyAccessor->getValue($this->formValues, '[persons]') ?? [];
        $count = \count($persons);
        if ($count > 0 && $this->activePersonIndex >= $count) {
            $this->activePersonIndex = $count - 1;
        } elseif (0 === $count) {
            $this->activePersonIndex = 0;
        }
    }

    #[LiveAction]
    public function generate(EntityManagerInterface $em, UrlGeneratorInterface $urls): void
    {
        $this->ensureAdmin();
        $this->submitForm();

        /** @var DocumentRequest $request */
        $request = $this->getForm()->getData();
        $request->setCreatedAt(new \DateTimeImmutable());

        // Repair position numbering — collection removals can leave holes.
        $i = 0;
        foreach ($request->getPersons() as $person) {
            $person->setPosition($i++);
        }

        $em->persist($request);
        $em->flush();

        $downloadUrl = $urls->generate('admin_tools_documents_request_pdf', [
            '_locale' => $request->getLanguage()->value,
            'adminPrefix' => $this->adminPrefix,
            'id' => $request->getId(),
        ]);

        $this->dispatchBrowserEvent('document-request:download', [
            'url' => $downloadUrl,
        ]);

        // Reset form so the admin can immediately start another request.
        $this->request = $this->buildEmptyRequest();
        $this->activePersonIndex = 0;
        $this->resetForm();
    }

    /**
     * Fresh request preloaded with one empty person — saves the admin a
     * click on first render. The collection grows from there via the
     * LiveCollectionTrait `addCollectionItem` action.
     */
    private function buildEmptyRequest(): DocumentRequest
    {
        $request = new DocumentRequest();
        $request->setLanguage(RequestLanguage::FR);
        $request->setTypology(HouseholdTypology::ONE_TENANT);
        // PersonRequest is left without a role so the segmented pill renders
        // empty — the admin has to pick one before submitting.
        $request->addPerson(new PersonRequest());

        return $request;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
