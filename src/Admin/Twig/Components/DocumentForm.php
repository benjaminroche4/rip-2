<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Entity\Document;
use App\Admin\Form\DocumentFormType;
use App\Admin\Service\DocumentSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Admin form for creating a Document, rendered inside the <dialog> that
 * Admin:DocumentList puts around it. The component is publicly reachable
 * on the /_components/... route, so we re-check ROLE_ADMIN on every mount
 * and LiveAction. The dialog's open/close state is preserved across
 * re-renders via data-live-ignore on the host element — only this inner
 * form region morphs when validation errors come back.
 */
#[AsLiveComponent(name: 'Admin:DocumentForm', template: 'components/Admin/DocumentForm.html.twig')]
final class DocumentForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Document $document = null;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->document ??= new Document();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(DocumentFormType::class, $this->document ??= new Document());
    }

    #[LiveAction]
    public function save(EntityManagerInterface $em, DocumentSlugger $slugger): void
    {
        $this->ensureAdmin();
        // submitForm() throws UnprocessableEntityHttpException when invalid,
        // which the bundle catches and turns into a re-render with errors.
        $this->submitForm();

        /** @var Document $document */
        $document = $this->getForm()->getData();
        $document->setSlug($slugger->slugify((string) $document->getNameFr()));
        $document->setCreatedAt(new \DateTimeImmutable());

        $em->persist($document);
        $em->flush();

        // Notify the sibling Admin:DocumentList to re-render its rows.
        $this->emit('document:created');
        // Tell the host <dialog> (in DocumentList template) to close itself.
        $this->dispatchBrowserEvent('document-dialog:close');

        // Reset so the next opening starts on a blank Document, not the
        // one we just persisted.
        $this->document = new Document();
        $this->resetForm();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
