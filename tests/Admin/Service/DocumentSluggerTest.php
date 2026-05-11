<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Entity\Document;
use App\Admin\Repository\DocumentRepository;
use App\Admin\Service\DocumentSlugger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test — the slugger only relies on AsciiSlugger and a repository
 * lookup, both of which we drive via a fake repository to keep tests fast
 * and isolated from the database.
 */
#[CoversClass(DocumentSlugger::class)]
final class DocumentSluggerTest extends TestCase
{
    public function testItGeneratesAKebabCaseSlugFromAFrenchName(): void
    {
        $slugger = $this->buildSlugger([]);

        self::assertSame('bail-type-paris', $slugger->slugify('Bail type Paris'));
    }

    public function testItStripsAccentsAndDiacritics(): void
    {
        $slugger = $this->buildSlugger([]);

        self::assertSame('declaration-prealable', $slugger->slugify('Déclaration préalable'));
    }

    public function testItAppendsADisambiguationSuffixOnCollision(): void
    {
        $slugger = $this->buildSlugger(['bail-type']);

        self::assertSame('bail-type-2', $slugger->slugify('Bail type'));
    }

    public function testItKeepsIncrementingUntilItFindsAFreeSlug(): void
    {
        $slugger = $this->buildSlugger(['bail-type', 'bail-type-2', 'bail-type-3']);

        self::assertSame('bail-type-4', $slugger->slugify('Bail type'));
    }

    public function testItFallsBackToAPlaceholderForPathologicalInput(): void
    {
        $slugger = $this->buildSlugger([]);

        // Only punctuation / non-ASCII → AsciiSlugger emits an empty string,
        // so the service must still return a valid slug.
        self::assertSame('document', $slugger->slugify('---'));
    }

    public function testItTrimsWhitespace(): void
    {
        $slugger = $this->buildSlugger([]);

        self::assertSame('bail-type', $slugger->slugify('   Bail type   '));
    }

    public function testItTruncatesVeryLongNames(): void
    {
        $slugger = $this->buildSlugger([]);
        $longName = str_repeat('a', 300);

        $slug = $slugger->slugify($longName);

        // BASE_MAX_LENGTH is 140 in the slugger to keep room for a "-99" suffix.
        self::assertLessThanOrEqual(140, mb_strlen($slug));
        self::assertMatchesRegularExpression('/^a+$/', $slug);
    }

    /** @param list<string> $existingSlugs */
    private function buildSlugger(array $existingSlugs): DocumentSlugger
    {
        $repository = new class($existingSlugs) extends DocumentRepository {
            /** @param list<string> $existing */
            public function __construct(private readonly array $existing)
            {
                // intentionally skip parent ctor — we only stub findOneBySlug
            }

            #[\Override]
            public function findOneBySlug(string $slug): ?Document
            {
                if (\in_array($slug, $this->existing, true)) {
                    return new Document();
                }

                return null;
            }
        };

        return new DocumentSlugger($repository);
    }
}
