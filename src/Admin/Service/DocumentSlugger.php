<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Admin\Repository\DocumentRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Generates URL-safe, unique slugs for Document entities. The slug column
 * is unique at the DB level, so this service also resolves collisions by
 * appending -2, -3, … so admins can create two documents with the same
 * French name without manually deduplicating.
 */
final readonly class DocumentSlugger
{
    /** Max length of the slug column on Document. */
    private const MAX_LENGTH = 150;
    /** Reserve a few chars for the "-99" disambiguation suffix. */
    private const BASE_MAX_LENGTH = 140;

    private SluggerInterface $slugger;

    public function __construct(
        private DocumentRepository $repository,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    public function slugify(string $name): string
    {
        $base = strtolower((string) $this->slugger->slug(trim($name)));
        $base = mb_substr($base, 0, self::BASE_MAX_LENGTH);

        if ('' === $base) {
            // Pathological input ("---", emojis only, …) — fall back to a
            // deterministic placeholder so we still produce a valid slug
            // rather than throwing on the user-facing form.
            $base = 'document';
        }

        $candidate = $base;
        $suffix = 2;
        while (null !== $this->repository->findOneBySlug($candidate)) {
            $candidate = $base.'-'.$suffix++;
            if (mb_strlen($candidate) > self::MAX_LENGTH) {
                $base = mb_substr($base, 0, self::BASE_MAX_LENGTH - 1);
                $candidate = $base.'-'.$suffix;
            }
        }

        return $candidate;
    }
}
