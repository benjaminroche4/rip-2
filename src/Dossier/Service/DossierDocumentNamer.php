<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\DossierDocument;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Coherent display name for a deposited file, replacing the client's own
 * file name ("IMG_4562.pdf", "scan final v2.pdf"...): the piece type plus
 * the person it belongs to, e.g. "Pièce d'identité - Jean Dupont.pdf",
 * with a position suffix when a piece holds several files. Always in
 * French: the rental file ultimately goes to French landlords, whatever
 * the tenant's contact language. The stored name stays a UUID.
 */
final readonly class DossierDocumentNamer
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** Call before addFile(): the position suffix counts existing files. */
    public function displayName(DossierDocument $document, string $extension): string
    {
        $label = trim($this->translator->trans($document->getType()?->labelKey() ?? '', locale: 'fr'));
        if ('' === $label) {
            $label = 'Document';
        }
        $owner = trim(trim((string) $document->getPerson()?->getFirstName()).' '.trim((string) $document->getPerson()?->getLastName()));
        $position = \count($document->getFiles()) + 1;

        $name = $label
            .('' !== $owner ? ' - '.$owner : '')
            .($position > 1 ? ' ('.$position.')' : '')
            .'.'.('' !== $extension ? $extension : 'pdf');

        return $this->sanitize($name);
    }

    /**
     * File-system and header safe display name: it ends up in zip entries
     * and Content-Disposition fallbacks. Also applied to manual renames.
     */
    public function sanitize(string $name): string
    {
        $name = (string) preg_replace('#[\\\\/:*?"<>|]+#', ' ', $name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return mb_substr($name, 0, 255);
    }
}
