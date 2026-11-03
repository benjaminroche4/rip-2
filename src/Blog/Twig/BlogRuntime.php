<?php

declare(strict_types=1);

namespace App\Blog\Twig;

use App\Blog\Repository\BlogRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

final readonly class BlogRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private BlogRepository $blogRepository,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Published posts in the current locale (cached by the repository, invalidated by the Sanity webhook).
     */
    public function postCount(): int
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';

        return $this->blogRepository->countAll($locale);
    }
}
