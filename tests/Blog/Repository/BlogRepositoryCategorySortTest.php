<?php

declare(strict_types=1);

namespace App\Tests\Blog\Repository;

use App\Blog\Domain\PostMapper;
use App\Blog\Repository\BlogRepository;
use App\Shared\Sanity\SanityService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * GROQ sorts by code point ("Étudiants" after "Vivre à Paris"):
 * category tabs must be sorted alphabetically, ignoring accents.
 */
final class BlogRepositoryCategorySortTest extends TestCase
{
    private function repository(): BlogRepository
    {
        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturn([
            ['name' => 'Vivre à Paris', 'slug' => 'vivre-a-paris', 'count' => 3],
            ['name' => 'Étudiants', 'slug' => 'etudiants', 'count' => 1],
            ['name' => 'Location', 'slug' => 'location', 'count' => 2],
            ['name' => 'Expatriés', 'slug' => 'expatries', 'count' => 4],
        ]);

        return new BlogRepository($sanity, new TagAwareAdapter(new ArrayAdapter()), new PostMapper());
    }

    public function testItSortsBlogCategoriesIgnoringAccents(): void
    {
        $names = array_column($this->repository()->findCategoriesWithCount('fr'), 'name');

        self::assertSame(['Étudiants', 'Expatriés', 'Location', 'Vivre à Paris'], $names);
    }

    public function testItSortsAuthorCategoriesIgnoringAccents(): void
    {
        $names = array_column($this->repository()->findCategoriesWithCountByAuthor('fr', 'jane-doe'), 'name');

        self::assertSame(['Étudiants', 'Expatriés', 'Location', 'Vivre à Paris'], $names);
    }
}
