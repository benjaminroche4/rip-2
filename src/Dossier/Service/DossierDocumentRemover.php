<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\PersonName;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Destructive side of the file module: removing a single deposited file or
 * withdrawing a whole requested piece, storage cleanup included. Audit
 * trail and status advancement included; live events stay in the component.
 */
final class DossierDocumentRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentStorage $storage,
        private readonly DossierEventLogger $events,
        private readonly DossierStatusAdvancer $advancer,
        private readonly Security $security,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /**
     * Deletes a deposited file (unreadable, wrong piece...) from disk and
     * database. When it was the document's last file the piece goes back to
     * "requested" so the tenant sees it must be deposited again.
     */
    public function deleteFile(Dossier $dossier, int $fileId): void
    {
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles() as $file) {
                    if ($file->getId() !== $fileId) {
                        continue;
                    }
                    $fileName = (string) $file->getOriginalName();
                    $document->removeFile($file);
                    if ($document->getFiles()->isEmpty()) {
                        $document->setStatus(DossierDocumentStatus::Requested);
                        $document->setReceivedAt(null);
                    }
                    $this->events->log($dossier, 'document_file_deleted', [
                        'piece' => $document->getType()?->labelKey() ?? '',
                        'tenant' => PersonName::firstLast($document->getPerson()),
                        'file' => $fileName,
                    ]);
                    // DB first: a storage hiccup then leaves at worst an
                    // orphan blob, never a row pointing at a vanished file.
                    $this->em->flush();
                    try {
                        $this->storage->delete($dossier, $file);
                    } catch (\Throwable $e) {
                        $this->securityLogger->warning('Dossier file blob deletion failed: '.$e->getMessage(), [
                            'dossier' => (string) $dossier->getReference(),
                            'file' => $fileId,
                        ]);
                    }
                    // The heaviest destructive mutation of the context: it
                    // belongs on the audit channel like the dossier deletion.
                    $this->securityLogger->notice('Dossier document file deleted', [
                        'actor' => $this->security->getUser()?->getUserIdentifier(),
                        'dossier' => (string) $dossier->getReference(),
                        'file' => $fileName,
                    ]);
                    $this->advancer->advance($dossier);

                    return;
                }
            }
        }

        throw new NotFoundHttpException('File not found on this dossier.');
    }

    /**
     * Withdraws a requested piece entirely (wrong type picked, piece no
     * longer needed): the deposited files are removed from storage first,
     * then the row itself, so the tenant stops seeing it in the deposit
     * page. Unlike deleteFile() this leaves nothing behind.
     */
    public function deletePiece(Dossier $dossier, DossierDocument $document): void
    {
        $person = $document->getPerson();

        foreach ($document->getFiles() as $file) {
            // Storage may be down: the piece must still disappear from the
            // dossier, an orphan blob is preferable to a stuck row.
            try {
                $this->storage->delete($dossier, $file);
            } catch (\Throwable) {
            }
        }

        $this->events->log($dossier, 'document_deleted', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => PersonName::firstLast($person),
        ]);

        $person?->removeDocument($document);
        $this->em->remove($document);
        $this->em->flush();
        $this->securityLogger->notice('Dossier document piece deleted', [
            'actor' => $this->security->getUser()?->getUserIdentifier(),
            'dossier' => (string) $dossier->getReference(),
            'piece' => $document->getType()?->labelKey() ?? '',
        ]);
        $this->advancer->advance($dossier);
    }
}
