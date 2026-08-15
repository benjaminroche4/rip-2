<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\PersonName;
use App\Dossier\Entity\Dossier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Read-only projections of the file module card: tenant rows with their
 * requested pieces, eligible email recipients, deposit counters and the
 * public deposit link. Pure display shaping, no mutation.
 */
final class DossierFileModuleViewFactory
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Tenants of the file module with their requested pieces.
     *
     * @return list<array{id: int, name: string, documents: list<array<string, mixed>>}>
     */
    public function tenants(Dossier $dossier): array
    {
        $tenants = [];
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT !== $person->getRole()) {
                continue;
            }
            $documents = [];
            foreach ($person->getDocuments() as $document) {
                $files = [];
                foreach ($document->getFiles() as $file) {
                    $files[] = [
                        'id' => (int) $file->getId(),
                        'name' => (string) $file->getOriginalName(),
                        'size' => (int) $file->getSize(),
                        'mimeType' => (string) $file->getMimeType(),
                        'uploadedAt' => $file->getUploadedAt(),
                    ];
                }
                $documents[] = [
                    'id' => (int) $document->getId(),
                    'typeLabelKey' => $document->getType()?->labelKey() ?? '',
                    'status' => $document->getStatus()->value,
                    'statusLabelKey' => $document->getStatus()->labelKey(),
                    'requestedAt' => $document->getRequestedAt(),
                    'receivedAt' => $document->getReceivedAt(),
                    'refusalReason' => (string) $document->getRefusalReason(),
                    'description' => (string) $document->getDescription(),
                    'files' => $files,
                ];
            }
            $tenants[] = [
                'id' => (int) $person->getId(),
                'name' => PersonName::lastFirst($person),
                'documents' => $documents,
            ];
        }

        return $tenants;
    }

    /**
     * People of the dossier who can receive the request email (any role,
     * as long as an email exists: the follow-up contact may be the payer).
     *
     * @return list<array{id: int, name: string, email: string, primary: bool}>
     */
    public function recipients(Dossier $dossier): array
    {
        $recipients = [];
        foreach ($dossier->getPersons() as $person) {
            if ('' === trim((string) $person->getEmail())) {
                continue;
            }
            $recipients[] = [
                'id' => (int) $person->getId(),
                'name' => PersonName::firstLast($person),
                'email' => (string) $person->getEmail(),
                'primary' => $person->isPrimaryContact(),
            ];
        }

        return $recipients;
    }

    /**
     * "x/y déposées" on the module card summary: pieces holding a deposit
     * (received or validated) over the pieces requested.
     *
     * @return array{deposited: int, total: int}
     */
    public function pieceCounts(Dossier $dossier): array
    {
        $deposited = 0;
        $total = 0;
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                ++$total;
                if (\in_array($document->getStatus(), [DossierDocumentStatus::Received, DossierDocumentStatus::Validated], true)) {
                    ++$deposited;
                }
            }
        }

        return ['deposited' => $deposited, 'total' => $total];
    }

    /** True as soon as one file has been deposited on the dossier. */
    public function hasDepositedFiles(Dossier $dossier): bool
    {
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                if (!$document->getFiles()->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Public deposit link (pairing code prefilled) shown at the bottom of
     * the file module card, so the admin can hand it out directly. It only
     * exists once at least one piece has been requested.
     */
    public function depositUrl(Dossier $dossier): ?string
    {
        $hasRequest = false;
        foreach ($dossier->getPersons() as $person) {
            if (!$person->getDocuments()->isEmpty()) {
                $hasRequest = true;
            }
        }
        if (!$hasRequest) {
            return null;
        }

        return $this->urlGenerator->generate('app_dossier_deposit', [
            'code' => (string) $dossier->getPairingCode(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
