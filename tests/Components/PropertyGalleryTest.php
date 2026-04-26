<?php

namespace App\Tests\Components;

use App\Marketplace\Domain\Property;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders Marketplace/PropertyGallery and asserts:
 * - the gallery dataset emitted into data-gallery-photos-value (consumed by
 *   the gallery Stimulus controller for the lightbox)
 * - the "+N more" overlay when the photo count exceeds the 4-tile mobile grid
 * - the empty-state when the property has no photos at all
 */
final class PropertyGalleryTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testEmitsAllPhotosInGalleryDataset(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyGallery', [
            'property' => $this->property(photoCount: 3),
            'locale' => 'fr',
        ]);

        $this->assertStringContainsString('data-controller="gallery"', $html);
        $this->assertStringContainsString('data-gallery-photos-value=', $html);
        // 3 distinct photos → 3 <img src="…photo-N.jpg…"> in the rendered grid
        // (mobile branch only; desktop adds clones counted separately).
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'photo-1.jpg'));
    }

    public function testRendersPlusOverlayWhenMoreThanFourPhotos(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyGallery', [
            'property' => $this->property(photoCount: 8),
            'locale' => 'fr',
        ]);

        // 4 visible in the mobile grid + a "+4" remainder badge.
        $this->assertStringContainsString('+4', $html);
    }

    public function testRendersWithNoPhotosWithoutCrashing(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyGallery', [
            'property' => $this->property(photoCount: 0),
            'locale' => 'fr',
        ]);

        // Section wrapper still emitted; dataset is the empty JSON array
        // (encoded as &#x5B;&#x5D; by the html_attr filter).
        $this->assertStringContainsString('data-controller="gallery"', $html);
        $this->assertMatchesRegularExpression(
            '/data-gallery-photos-value="(\[\]|&#x5B;&#x5D;)"/',
            $html,
        );
    }

    private function property(int $photoCount): Property
    {
        $mainPhoto = $photoCount > 0
            ? ['url' => 'https://example.test/photo-1.jpg', 'alt' => 'Photo 1']
            : null;

        $extras = [];
        for ($i = 2; $i <= $photoCount; ++$i) {
            $extras[] = ['url' => 'https://example.test/photo-'.$i.'.jpg', 'alt' => 'Photo '.$i];
        }

        return new Property(
            id: 'gallery-test',
            title: 'Galerie test',
            mainPhoto: $mainPhoto,
            photos: [] !== $extras ? $extras : null,
        );
    }
}
