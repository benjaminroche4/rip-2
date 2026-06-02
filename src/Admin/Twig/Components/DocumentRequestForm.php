<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Admin\Form\DocumentRequestType;
use App\Admin\Repository\DocumentRepository;
use App\Admin\Repository\DocumentRequestRepository;
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
 *   4. the form keeps its data on screen so the admin can fix a typo and
 *      re-download without re-typing everything from scratch
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
     * Id of the DocumentRequest being edited, or null in create mode. Drives
     * the `save` LiveAction (update the existing row vs. insert a new one) and
     * the submit button's label/icon. The form data still round-trips as an
     * id-less draft (see cloneAsDraft) so live edits survive re-renders.
     */
    #[LiveProp]
    public ?int $editId = null;

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
        private readonly DocumentRequestRepository $requestRepository,
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

    public function mount(?int $editId = null): void
    {
        $this->ensureAdmin();
        // Taken as a mount argument (not just the post-mount property
        // assignment) so it's already set while we seed the form below.
        $this->editId = $editId;
        if (null === $this->request && null !== $this->editId) {
            // Edit mode: seed the form from the saved request, but as an id-less
            // draft so live edits round-trip without Doctrine re-fetching (and
            // discarding) the managed entity between renders. The id stays in
            // $editId; save() uses it to update the right row.
            $existing = $this->requestRepository->find($this->editId);
            $this->request = $existing ? $this->cloneAsDraft($existing) : null;
        }
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

    /**
     * Persists the form without downloading. Create mode inserts a new row;
     * edit mode updates the one identified by $editId. Dispatches
     * 'document-request:saved' so the client can clear its unsaved-changes flag.
     */
    #[LiveAction]
    public function save(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();
        $request = $this->upsert($em);

        $this->dispatchBrowserEvent('document-request:saved', [
            'id' => $request->getId(),
        ]);
    }

    /**
     * Persists the form (a PDF can only be served from a saved row) and then
     * triggers the download via 'document-request:download'.
     */
    #[LiveAction]
    public function download(EntityManagerInterface $em, UrlGeneratorInterface $urls): void
    {
        $this->ensureAdmin();
        $request = $this->upsert($em);

        $downloadUrl = $urls->generate('admin_tools_documents_request_pdf', [
            '_locale' => $request->getLanguage()->value,
            'adminPrefix' => $this->adminPrefix,
            'id' => $request->getId(),
        ]);

        $this->dispatchBrowserEvent('document-request:download', [
            'url' => $downloadUrl,
        ]);
    }

    /**
     * Create-or-update the request from the submitted form, then re-seed the
     * form as an id-less draft so further edits round-trip and a re-submit
     * targets the same row (via $editId) instead of inserting a duplicate.
     */
    private function upsert(EntityManagerInterface $em): DocumentRequest
    {
        $this->submitForm();

        /** @var DocumentRequest $draft */
        $draft = $this->getForm()->getData();

        $existing = null !== $this->editId ? $this->requestRepository->find($this->editId) : null;

        if (null !== $existing) {
            $existing->setTypology($draft->getTypology());
            $existing->setLanguage($draft->getLanguage());
            $existing->setDriveLink($draft->getDriveLink());
            $existing->setNote($draft->getNote());

            // Replace the person collection wholesale: orphanRemoval drops the
            // old rows, the freshly bound PersonRequest objects (with their
            // documents) are re-attached in form order.
            foreach ($existing->getPersons()->toArray() as $person) {
                $existing->removePerson($person);
            }
            $i = 0;
            foreach ($draft->getPersons() as $person) {
                $existing->addPerson($person);
                $person->setPosition($i++);
            }

            $request = $existing;
        } else {
            $draft->setCreatedAt(new \DateTimeImmutable());

            // Repair position numbering — collection removals can leave holes.
            $i = 0;
            foreach ($draft->getPersons() as $person) {
                $person->setPosition($i++);
            }

            $em->persist($draft);
            $request = $draft;
        }

        $em->flush();

        // Subsequent saves/downloads now update this row in place.
        $this->editId = $request->getId();

        // detach() matters: $request is persisted and re-submitting would
        // otherwise hit a managed-entity conflict when LiveComponent
        // re-hydrates a copy on the next request.
        $em->detach($request);
        $this->request = $this->cloneAsDraft($request);

        return $request;
    }

    /**
     * Returns a fresh, unmanaged DocumentRequest carrying the same field
     * values as $source (typology, language, drive link, note, persons
     * with their documents). Used right after a successful save so the
     * form keeps its UI state without holding a reference to a persisted
     * entity — a re-submit then creates a brand-new DocumentRequest row
     * rather than updating the previous one.
     */
    private function cloneAsDraft(DocumentRequest $source): DocumentRequest
    {
        $draft = new DocumentRequest();
        $draft->setTypology($source->getTypology());
        $draft->setLanguage($source->getLanguage());
        $draft->setDriveLink($source->getDriveLink());
        $draft->setNote($source->getNote());

        foreach ($source->getPersons() as $person) {
            $copy = new PersonRequest();
            $copy->setRole($person->getRole());
            $copy->setFirstName($person->getFirstName());
            $copy->setLastName($person->getLastName());
            foreach ($person->getDocuments() as $doc) {
                $copy->addDocument($doc);
            }
            $draft->addPerson($copy);
        }

        return $draft;
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
