<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Shared\Google\DriveGateway;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provisions and links the Drive structure of a dossier:
 *
 *   [Shared Drive] / DS-000123 Nom / Nom Prénom / <pièces>
 *
 * The dossier folder is created up-front (at dossier creation), person
 * sub-folders lazily on the first deposited piece, so no empty folders
 * clutter the drive. The manager (staff) gets read access on the dossier
 * folder, re-synced whenever the manager changes.
 *
 * Everything is best-effort: when Drive is unconfigured (dev/test, empty
 * env) every method is a no-op and returns null, and any API failure is
 * logged and swallowed so the dossier flow never breaks on a Drive outage.
 * Folder/permission ids are persisted on the entities so lookups stay
 * deterministic and never rely on names.
 */
final readonly class DossierDriveProvisioner
{
    public function __construct(
        private DriveGateway $drive,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ensures the dossier root folder exists in the Shared Drive and returns
     * its id (null when Drive is off or provisioning failed). Idempotent.
     */
    public function ensureDossierFolder(Dossier $dossier): ?string
    {
        if (!$this->drive->isConfigured()) {
            return null;
        }
        if (null !== $dossier->getDriveFolderId()) {
            return $dossier->getDriveFolderId();
        }

        try {
            $name = trim(trim((string) $dossier->getReference()).' '.trim((string) $dossier->getName()));
            $id = $this->drive->ensureFolder($this->safe($name), $this->drive->sharedDriveId(), array_filter([
                'dossierReference' => (string) $dossier->getReference(),
                'contactReference' => (string) $dossier->getSourceContactReference(),
                'managerEmail' => (string) $dossier->getManager()?->getEmail(),
            ]));
            if (null === $id) {
                return null;
            }
            $dossier->setDriveFolderId($id);
            $this->em->flush();

            return $id;
        } catch (\Throwable $e) {
            $this->logger->error('Dossier drive folder provisioning failed: '.$e->getMessage(), ['dossier' => $dossier->getReference()]);

            return null;
        }
    }

    /**
     * Ensures the person's sub-folder exists (creating the dossier folder
     * first if needed) and returns its id, or null when unavailable.
     */
    public function ensurePersonFolder(Dossier $dossier, DossierPerson $person): ?string
    {
        if (!$this->drive->isConfigured()) {
            return null;
        }
        if (null !== $person->getDriveFolderId()) {
            return $person->getDriveFolderId();
        }

        $parentId = $this->ensureDossierFolder($dossier);
        if (null === $parentId) {
            return null;
        }

        try {
            $name = trim(trim((string) $person->getLastName()).' '.trim((string) $person->getFirstName()));
            $id = $this->drive->ensureFolder($this->safe('' !== $name ? $name : 'personne'), $parentId, array_filter([
                'dossierReference' => (string) $dossier->getReference(),
                'personRole' => $person->getRole()?->value ?? '',
                'personEmail' => (string) $person->getEmail(),
            ]));
            if (null === $id) {
                return null;
            }
            $person->setDriveFolderId($id);
            $this->em->flush();

            return $id;
        } catch (\Throwable $e) {
            $this->logger->error('Dossier person drive folder provisioning failed: '.$e->getMessage(), ['dossier' => $dossier->getReference()]);

            return null;
        }
    }

    /**
     * Grants the current manager read access on the dossier folder and
     * revokes the previous grant. No-op when Drive is off or the folder has
     * not been provisioned yet. Best-effort.
     */
    public function syncManagerShare(Dossier $dossier): void
    {
        if (!$this->drive->isConfigured()) {
            return;
        }
        $folderId = $dossier->getDriveFolderId();
        if (null === $folderId) {
            return;
        }

        try {
            $previous = $dossier->getDriveManagerPermissionId();
            if (null !== $previous) {
                $this->drive->removePermission($folderId, $previous);
                $dossier->setDriveManagerPermissionId(null);
            }

            $email = trim((string) $dossier->getManager()?->getEmail());
            $permissionId = '' !== $email ? $this->drive->shareRead($folderId, $email) : null;
            $dossier->setDriveManagerPermissionId($permissionId);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Dossier manager drive share sync failed: '.$e->getMessage(), ['dossier' => $dossier->getReference()]);
        }
    }

    /** Drive-safe folder/file segment (no slashes or control chars). */
    private function safe(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', trim($value));

        return (string) preg_replace('/[[:cntrl:]]+/', '', $value);
    }
}
