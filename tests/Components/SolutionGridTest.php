<?php

namespace App\Tests\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders the Section:SolutionGrid component with mock props and asserts
 * that the markup includes the expected heading, grid structure, and the
 * right number of cards. Documents the public API of the component so a
 * regression that drops a class or skips translations is caught at CI.
 */
final class SolutionGridTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersSixCardsForFindAccommodation(): void
    {
        $rendered = $this->renderTwigComponent('Section:SolutionGrid', [
            'keyPrefix' => 'findAccommodation.solution',
            'icons' => [
                'gg:search',
                'hugeicons:diamond-02',
                'mdi:paper-check-outline',
                'humbleicons:key',
                'icon-park-outline:people',
                'mdi:paris',
            ],
        ]);

        $html = (string) $rendered;

        // 6 cards rendered (one <h3> per card).
        $this->assertSame(6, substr_count($html, '<h3'), 'Expected one <h3> per card.');

        // Grid + framed gradient cards applied.
        $this->assertStringContainsString('grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4', $html);
        $this->assertSame(6, substr_count($html, 'group-hover:from-primary'), 'Expected one gradient card per item.');

        // 6 paragraph descriptions (one per card) — checks the second
        // translation lookup `.text` is wired up, not just the title.
        $this->assertSame(6, substr_count($html, 'group-hover:text-white/90'));
    }

    public function testCardCountFollowsIconsArrayLength(): void
    {
        $rendered = $this->renderTwigComponent('Section:SolutionGrid', [
            'keyPrefix' => 'foo.bar',
            'icons' => ['lucide:check', 'lucide:x', 'lucide:home'],
        ]);

        $this->assertSame(3, substr_count((string) $rendered, '<h3'));
    }
}
