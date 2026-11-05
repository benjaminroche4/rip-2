<?php

namespace App\Tests\CaseStudy\Domain;

use App\CaseStudy\Domain\CaseStudyDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

final class CaseStudyDocumentTest extends KernelTestCase
{
    public function testEveryPartOfTheTenantFileHasALabelADescriptionAndAnIcon(): void
    {
        self::bootKernel();
        /** @var TranslatorBagInterface $translator */
        $translator = static::getContainer()->get('translator');
        $iconsDir = static::getContainer()->getParameter('kernel.project_dir').'/assets/icons/';

        foreach (CaseStudyDocument::cases() as $document) {
            foreach (['fr', 'en'] as $locale) {
                $catalogue = $translator->getCatalogue($locale);
                self::assertTrue($catalogue->defines($document->labelKey()), $locale.': '.$document->labelKey());
                self::assertTrue($catalogue->defines($document->descriptionKey()), $locale.': '.$document->descriptionKey());
            }
            self::assertFileExists($iconsDir.str_replace(':', '/', $document->icon()).'.svg');
        }
    }
}
