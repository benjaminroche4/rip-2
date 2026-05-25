<?php

namespace App\Tests\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders the Section:AdvantageList component and asserts the per-feature
 * translation keys resolve. Regression guard for the `companies.advantage`
 * block, whose items used to be nested under an extra `features:` level
 * (and `subtitle` instead of `subTitle`), so the keys rendered raw.
 */
final class AdvantageListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testCompaniesAdvantageKeysAreTranslated(): void
    {
        $rendered = $this->renderTwigComponent('Section:AdvantageList', [
            'keyPrefix' => 'companies.advantage',
            'imageSrc' => 'medias/website/relocation-paris-5.webp',
            'imageAlt' => 'Relocation In Paris',
        ]);

        $html = (string) $rendered;

        // Translated content is present...
        $this->assertStringContainsString('Faites de Paris l\'atout majeur de vos recrutements', $html);
        $this->assertStringContainsString('Gain de temps stratégique', $html);
        $this->assertStringContainsString('Sérénité et confiance', $html);

        // ...and no raw translation key leaks through.
        $this->assertStringNotContainsString('companies.advantage.', $html);
    }
}
