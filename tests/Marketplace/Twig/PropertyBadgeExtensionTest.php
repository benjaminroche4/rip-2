<?php

namespace App\Tests\Marketplace\Twig;

use App\Marketplace\Domain\Property;
use App\Marketplace\Twig\Extension\PropertyBadgeExtension;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the single source of truth for "which badge does a card show":
 * `new` wins over the category, only the first category drives the badge, and
 * an unrecognized category falls back to its raw name.
 */
final class PropertyBadgeExtensionTest extends TestCase
{
    private PropertyBadgeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new PropertyBadgeExtension();
    }

    public function testNewWinsOverCategory(): void
    {
        $badge = $this->extension->resolve($this->property(
            createdAt: new \DateTimeImmutable('-1 day'),
            showCategoryOnCard: true,
            categoryName: 'Premium',
            categoryList: [['name' => 'Premium', 'slug' => 'premium']],
        ));

        $this->assertSame('new', $badge['type']);
    }

    public function testFirstCategorySlugResolvesToType(): void
    {
        $badge = $this->extension->resolve($this->property(
            showCategoryOnCard: true,
            categoryName: 'Premium',
            categoryList: [['name' => 'Premium', 'slug' => 'premium']],
        ));

        $this->assertSame('premium', $badge['type']);
        $this->assertSame('Premium', $badge['label']);
    }

    public function testResolvesFrenchAndEnglishSlugAliases(): void
    {
        $fr = $this->extension->resolve($this->property(
            showCategoryOnCard: true,
            categoryName: 'Coup de cœur',
            categoryList: [['slug' => 'coup-de-coeur']],
        ));
        $en = $this->extension->resolve($this->property(
            showCategoryOnCard: true,
            categoryName: 'Ready to move',
            categoryList: [['slug' => 'ready-to-move']],
        ));

        $this->assertSame('featured', $fr['type']);
        $this->assertSame('readyToMove', $en['type']);
    }

    public function testUnknownCategoryFallsBackToRawName(): void
    {
        $badge = $this->extension->resolve($this->property(
            showCategoryOnCard: true,
            categoryName: 'Vue Tour Eiffel',
            categoryList: [['slug' => 'vue-tour-eiffel']],
        ));

        $this->assertNull($badge['type']);
        $this->assertSame('Vue Tour Eiffel', $badge['label']);
    }

    public function testNoBadgeWhenCategoryHiddenAndNotNew(): void
    {
        $badge = $this->extension->resolve($this->property(
            showCategoryOnCard: false,
            categoryName: 'Premium',
            categoryList: [['slug' => 'premium']],
        ));

        $this->assertNull($badge['type']);
        $this->assertNull($badge['label']);
    }

    /**
     * @param array<int, array<string, mixed>>|null $categoryList
     */
    private function property(
        ?\DateTimeImmutable $createdAt = null,
        ?bool $showCategoryOnCard = null,
        ?string $categoryName = null,
        ?array $categoryList = null,
    ): Property {
        return new Property(
            id: 'abc123',
            createdAt: $createdAt ?? new \DateTimeImmutable('-1 month'),
            showCategoryOnCard: $showCategoryOnCard,
            categoryName: $categoryName,
            categoryList: $categoryList,
        );
    }
}
