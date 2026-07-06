<?php

namespace App\Tests\Shared\Sanity;

use App\Shared\Sanity\PortableTextRenderer;
use PHPUnit\Framework\TestCase;

final class PortableTextRendererTest extends TestCase
{
    private PortableTextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PortableTextRenderer('https://relocation-in-paris.fr');
    }

    /**
     * @param array<int, array<string, mixed>> $markDefs
     * @param array<int, string>               $marks
     *
     * @return array<int, array<string, mixed>>
     */
    private function textBlock(string $style, string $text, array $markDefs = [], array $marks = []): array
    {
        return [[
            '_type' => 'block',
            'style' => $style,
            'markDefs' => $markDefs,
            'children' => [['_type' => 'span', 'text' => $text, 'marks' => $marks]],
        ]];
    }

    public function testItRendersH2StyleAsH2Tag(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock('h2', 'Section title'));

        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('Section title</h2>', $html);
        self::assertStringNotContainsString('<p', $html);
    }

    public function testItStillRendersNormalStyleAsParagraph(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock('normal', 'Plain text'));

        self::assertStringContainsString('<p', $html);
        self::assertStringContainsString('Plain text</p>', $html);
    }

    public function testItRendersExternalLinkWithTargetBlank(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock(
            'normal',
            'external',
            [['_key' => 'l1', '_type' => 'link', 'href' => 'https://example.com/page']],
            ['l1'],
        ));

        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testItRendersRelativeInternalLinkWithoutTargetBlank(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock(
            'normal',
            'internal',
            [['_key' => 'l1', '_type' => 'link', 'href' => '/fr/nos-biens']],
            ['l1'],
        ));

        self::assertStringContainsString('href="/fr/nos-biens"', $html);
        self::assertStringNotContainsString('target="_blank"', $html);
        self::assertStringNotContainsString('noopener', $html);
    }

    public function testItRendersAbsoluteSameHostLinkWithoutTargetBlank(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock(
            'normal',
            'internal absolute',
            [['_key' => 'l1', '_type' => 'link', 'href' => 'https://relocation-in-paris.fr/fr/blog']],
            ['l1'],
        ));

        self::assertStringNotContainsString('target="_blank"', $html);
    }

    public function testItTreatsWwwVariantOfSiteHostAsInternal(): void
    {
        $html = $this->renderer->renderTextBlocks($this->textBlock(
            'normal',
            'www variant',
            [['_key' => 'l1', '_type' => 'link', 'href' => 'https://www.relocation-in-paris.fr/fr/blog']],
            ['l1'],
        ));

        self::assertStringNotContainsString('target="_blank"', $html);
    }
}
