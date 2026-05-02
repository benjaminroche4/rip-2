<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Builds a URL-safe slug from a user's display name. The slug is purely
 * decorative (the route resolves on the ULID), so a stale slug just
 * triggers a canonical redirect rather than a 404.
 */
final readonly class UserSlugger
{
    public function __construct(private SluggerInterface $slugger)
    {
    }

    public function slug(string $firstName, string $lastName, string $emailFallback): string
    {
        $candidate = trim($firstName.' '.$lastName);
        if ('' === $candidate) {
            $local = strstr($emailFallback, '@', true);
            $candidate = false === $local || '' === $local ? 'user' : $local;
        }

        $slug = $this->slugger->slug($candidate)->lower()->toString();

        return '' === $slug ? 'user' : $slug;
    }
}
