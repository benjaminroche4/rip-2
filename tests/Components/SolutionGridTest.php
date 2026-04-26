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

        // Section wrapper applied.
        $this->assertStringContainsString('rounded-2xl bg-neutral-100/80', $html);
        $this->assertStringContainsString('grid grid-cols-1 sm:grid-cols-2 gap-6 lg:grid-cols-3', $html);

        // 6 paragraph descriptions (one per card) — checks the second
        // translation lookup `.text` is wired up, not just the title.
        $this->assertSame(6, substr_count($html, 'class="flex-auto text-neutral-800/70'));
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
