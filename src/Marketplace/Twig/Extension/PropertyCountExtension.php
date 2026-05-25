<?php

namespace App\Marketplace\Twig\Extension;

use App\Marketplace\Repository\PropertyRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PropertyCountExtension extends AbstractExtension
{
    public function __construct(
        private readonly PropertyRepository $repository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('available_property_count', [$this, 'availablePropertyCount']),
        ];
    }

    /**
     * Number of published, non-rented properties for the current locale.
     * Cached at the repository level (tagged "marketplace").
     */
    public function availablePropertyCount(?string $locale = null): int
    {
        $locale ??= $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';

        return $this->repository->countAvailable($locale);
    }
}
