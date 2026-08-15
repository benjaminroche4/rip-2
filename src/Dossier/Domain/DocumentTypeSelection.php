<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Dossier\Entity\DossierPerson;

/**
 * Selection rules of the "request pieces" modal: which checked types are
 * valid, which are actually new for the tenant, and which pieces still
 * await a deposit (for the reminder email).
 */
final class DocumentTypeSelection
{
    private function __construct()
    {
    }

    /**
     * Checked values as validated enums (unknown values are dropped: a
     * stale DOM can post anything).
     *
     * @param list<string> $raw
     *
     * @return list<DossierDocumentType>
     */
    public static function clean(array $raw): array
    {
        $types = [];
        foreach (array_unique($raw) as $value) {
            $type = DossierDocumentType::tryFrom((string) $value);
            if (null !== $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Checked types not already requested for this tenant.
     *
     * @param list<string> $raw
     *
     * @return list<DossierDocumentType>
     */
    public static function newFor(DossierPerson $tenant, array $raw): array
    {
        $existing = [];
        foreach ($tenant->getDocuments() as $document) {
            $existing[] = $document->getType();
        }

        return array_values(array_filter(
            self::clean($raw),
            static fn (DossierDocumentType $type): bool => !\in_array($type, $existing, true),
        ));
    }

    /**
     * Pieces of the tenant still awaiting a deposit (requested or refused).
     *
     * @return list<DossierDocumentType>
     */
    public static function pendingFor(DossierPerson $tenant): array
    {
        $types = [];
        foreach ($tenant->getDocuments() as $document) {
            if (\in_array($document->getStatus(), [DossierDocumentStatus::Requested, DossierDocumentStatus::Refused], true)) {
                $type = $document->getType();
                if (null !== $type) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }
}
