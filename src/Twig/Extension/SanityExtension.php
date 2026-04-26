<?php

namespace App\Twig\Extension;

use App\Shared\Sanity\PortableTextRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig glue for Sanity Portable Text rendering.
 * The actual rendering lives in {@see PortableTextRenderer}.
 */
final class SanityExtension extends AbstractExtension
{
    public function __construct(
        private readonly PortableTextRenderer $renderer,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('sanity_to_html', $this->renderer->renderTextBlocks(...), ['is_safe' => ['html']]),
            new TwigFilter('slugify', $this->slugify(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sanity_segments', $this->renderer->buildSegments(...)),
            new TwigFunction('sanity_youtube_id', $this->renderer->extractYoutubeId(...)),
        ];
    }

    public function slugify(string $text): string
    {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
