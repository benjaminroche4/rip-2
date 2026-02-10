<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class SanityExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('sanity_to_html', [$this, 'renderTextBlocks'], ['is_safe' => ['html']]),
            new TwigFilter('slugify', [$this, 'slugify']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sanity_segments', [$this, 'buildSegments']),
            new TwigFunction('sanity_youtube_id', [$this, 'extractYoutubeId']),
        ];
    }

    /**
     * Splits the Portable Text content array into segments:
     * - { type: 'text', html: '<p>...</p><ul>...</ul>' }
     * - { type: 'image', url: '...', alt: '...' }
     * - { type: 'youtube', url: '...', videoId: '...', shortDescription: '...' }
     */
    public function buildSegments(array $blocks): array
    {
        $segments = [];
        $textBuffer = [];

        foreach ($blocks as $block) {
            $type = $block['_type'] ?? '';

            if ($type === 'block') {
                $textBuffer[] = $block;
            } else {
                // Flush text buffer before non-text block
                if (!empty($textBuffer)) {
                    $segments[] = ['type' => 'text', 'html' => $this->renderTextBlocks($textBuffer)];
                    $textBuffer = [];
                }

                if ($type === 'image') {
                    $segments[] = [
                        'type' => 'image',
                        'url' => $block['url'] ?? '',
                        'alt' => $block['alt'] ?? '',
                    ];
                } elseif ($type === 'youtube') {
                    $segments[] = [
                        'type' => 'youtube',
                        'url' => $block['url'] ?? '',
                        'videoId' => $this->extractYoutubeId($block['url'] ?? ''),
                        'shortDescription' => $block['shortDescription'] ?? '',
                    ];
                } else {
                    // Pass through unknown types as-is
                    $segments[] = array_merge(['type' => $type], $block);
                }
            }
        }

        // Flush remaining text
        if (!empty($textBuffer)) {
            $segments[] = ['type' => 'text', 'html' => $this->renderTextBlocks($textBuffer)];
        }

        return $segments;
    }

    /**
     * Renders only text blocks (block type) to HTML.
     */
    public function renderTextBlocks(array $blocks): string
    {
        $html = '';
        $listType = null;

        foreach ($blocks as $block) {
            if (($block['_type'] ?? '') !== 'block') {
                continue;
            }

            $isListItem = isset($block['listItem']);

            if (!$isListItem && $listType !== null) {
                $html .= $listType === 'bullet' ? '</ul>' : '</ol>';
                $listType = null;
            }

            if ($isListItem) {
                $newListType = $block['listItem'];
                if ($listType !== $newListType) {
                    if ($listType !== null) {
                        $html .= $listType === 'bullet' ? '</ul>' : '</ol>';
                    }
                    $html .= $newListType === 'bullet' ? '<ul>' : '<ol>';
                    $listType = $newListType;
                }
                $html .= '<li>' . $this->renderSpans($block) . '</li>';
            } else {
                $html .= $this->renderBlock($block);
            }
        }

        if ($listType !== null) {
            $html .= $listType === 'bullet' ? '</ul>' : '</ol>';
        }

        return $html;
    }

    private function renderBlock(array $block): string
    {
        $style = $block['style'] ?? 'normal';
        $content = $this->renderSpans($block);

        if ($content === '') {
            return '';
        }

        return match ($style) {
            'h1' => '<h1>' . $content . '</h1>',
            'h2' => '<h2>' . $content . '</h2>',
            'h3' => '<h3>' . $content . '</h3>',
            'h4' => '<h4>' . $content . '</h4>',
            'h5' => '<h5>' . $content . '</h5>',
            'h6' => '<h6>' . $content . '</h6>',
            'blockquote' => '<blockquote>' . $content . '</blockquote>',
            default => '<p>' . $content . '</p>',
        };
    }

    private function renderSpans(array $block): string
    {
        $children = $block['children'] ?? [];
        $markDefs = [];
        foreach ($block['markDefs'] ?? [] as $def) {
            $markDefs[$def['_key']] = $def;
        }

        $html = '';
        foreach ($children as $child) {
            if (($child['_type'] ?? '') !== 'span') {
                continue;
            }

            $text = htmlspecialchars($child['text'] ?? '', ENT_QUOTES);
            $marks = $child['marks'] ?? [];

            foreach ($marks as $mark) {
                if (isset($markDefs[$mark])) {
                    $def = $markDefs[$mark];
                    if (($def['_type'] ?? '') === 'link') {
                        $href = htmlspecialchars($def['href'] ?? '', ENT_QUOTES);
                        $text = '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
                    }
                } else {
                    $text = match ($mark) {
                        'strong' => '<strong>' . $text . '</strong>',
                        'em' => '<em>' . $text . '</em>',
                        'underline' => '<u>' . $text . '</u>',
                        'strike-through' => '<s>' . $text . '</s>',
                        'code' => '<code>' . $text . '</code>',
                        default => $text,
                    };
                }
            }

            $html .= $text;
        }

        return $html;
    }

    public function extractYoutubeId(string $url): ?string
    {
        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function slugify(string $text): string
    {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}