<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Entity\Document;
use App\Admin\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Lists every Document and hosts the modal form to create new ones.
 * Re-renders when Admin:DocumentForm emits 'document:created' so the
 * fresh row appears without a page reload. The dialog DOM lives in this
 * component's template but is tagged data-live-ignore, which means its
 * open/closed state survives this component's re-renders.
 *
 * Like other admin LiveComponents, the /_components/... route is public;
 * we re-check ROLE_ADMIN on mount.
 */
#[AsLiveComponent(name: 'Admin:DocumentList', template: 'components/Admin/DocumentList.html.twig')]
final class DocumentList
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $adminPrefix = '';

    /** @var list<Document>|null Memoizes the repository hit per render. */
    private ?array $documentsCache = null;

    public function __construct(
        private readonly DocumentRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /**
     * Just having a listener for the event triggers a re-render, which
     * naturally refreshes getDocuments() through the cleared cache below.
     */
    #[LiveListener('document:created')]
    public function onDocumentCreated(): void
    {
        $this->documentsCache = null;
    }

    /**
     * Removes a document by id. Silently no-ops on an unknown id so a race
     * (two admins deleting the same doc) doesn't surface as an error — the
     * row is gone after the next render either way.
     */
    #[LiveAction]
    public function delete(EntityManagerInterface $em, #[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $doc = $this->repository->find($id);
        if (null !== $doc) {
            $em->remove($doc);
            $em->flush();
        }
        $this->documentsCache = null;
    }

    /**
     * Opens the shared dialog pre-filled with the requested document. The
     * actual fetching + form population happens in the sibling DocumentForm
     * component, which listens for `document:edit-requested` and dispatches
     * the browser event that opens the <dialog>. Silently no-ops on an
     * unknown id for the same race-protection reason as delete().
     */
    #[LiveAction]
    public function edit(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        if (null === $this->repository->find($id)) {
            return;
        }

        $this->emit('document:edit-requested', ['id' => $id]);
    }

    /**
     * @return list<Document>
     */
    public function getDocuments(): array
    {
        // Pinned items float to the top, then most-recent-first within each
        // bucket. Limit 100 is enough headroom for the catalogue without
        // paginating.
        return $this->documentsCache ??= $this->repository->findBy(
            [],
            ['pinned' => 'DESC', 'createdAt' => 'DESC'],
            100,
        );
    }

    public function getTotalCount(): int
    {
        return \count($this->getDocuments());
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
