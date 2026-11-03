<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Translation parameters for internal links inside translated copy (FAQ answers):
 * a text writes `<a href="%pricingUrl%">…</a>` and gets the localized path, so no
 * URL is ever hardcoded in the translation files.
 *
 * Usage: `'some.key'|trans(internal_link_params())|raw`
 */
final class InternalLinkExtension extends AbstractExtension
{
    /** Placeholder => route name. */
    private const LINKS = [
        '%contactUrl%' => 'app_contact',
        '%callbackUrl%' => 'app_callback',
        '%pricingUrl%' => 'app_pricing',
        '%propertiesUrl%' => 'app_property',
        '%findAccommodationUrl%' => 'app_service_find_accommodation',
        '%companiesUrl%' => 'app_service_companies',
        '%landlordsUrl%' => 'app_service_landlords',
        '%blogUrl%' => 'app_blog',
    ];

    /** @var array<string, string>|null Per-request memo (paths depend on the current locale). */
    private ?array $params = null;

    private ?string $paramsLocale = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('internal_link_params', $this->params(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        $locale = $this->urlGenerator->getContext()->getParameter('_locale');
        if (null === $this->params || $this->paramsLocale !== $locale) {
            $this->paramsLocale = is_string($locale) ? $locale : null;
            $this->params = array_map($this->urlGenerator->generate(...), self::LINKS);
        }

        return $this->params;
    }
}
