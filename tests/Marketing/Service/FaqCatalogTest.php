<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Service;

use App\Marketing\Service\FaqCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

final class FaqCatalogTest extends KernelTestCase
{
    public function testEveryTopicAndQuestionIsTranslatedInBothLocales(): void
    {
        self::bootKernel();
        /** @var TranslatorBagInterface $translator */
        $translator = static::getContainer()->get('translator');
        $categories = (new FaqCatalog())->categories();

        $missing = [];
        foreach (['fr', 'en'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach ($categories as $category) {
                $keys = [$category->titleKey];
                if (null !== $category->noteKey) {
                    $keys[] = $category->noteKey.'.title';
                    $keys[] = $category->noteKey.'.text';
                }
                foreach ($category->entries as $entry) {
                    $keys[] = $entry->questionKey;
                    $keys[] = $entry->answerKey;
                }
                foreach ($keys as $key) {
                    if (!$catalogue->defines($key)) {
                        $missing[] = $locale.': '.$key;
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testTopicsHaveUniqueAnchorsAndNoDuplicateQuestion(): void
    {
        $categories = (new FaqCatalog())->categories();

        $slugs = array_map(static fn ($c): string => $c->slug, $categories);
        self::assertSame($slugs, array_unique($slugs));

        $questions = [];
        foreach ($categories as $category) {
            self::assertNotEmpty($category->entries);
            foreach ($category->entries as $entry) {
                $questions[] = $entry->questionKey;
            }
        }
        self::assertSame($questions, array_unique($questions));
    }
}
