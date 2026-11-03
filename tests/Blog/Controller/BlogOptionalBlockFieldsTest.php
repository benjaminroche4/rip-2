<?php

declare(strict_types=1);

namespace App\Tests\Blog\Controller;

use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sanity omits unset fields from the payload: every optional block field
 * must be read null-safe, otherwise strict_variables turns it into a 500.
 */
final class BlogOptionalBlockFieldsTest extends WebTestCase
{
    /** @param list<array<string, mixed>> $body */
    private function requestPostWithBody(array $body): KernelBrowser
    {
        $client = static::createClient();

        // BlogRepository caches by slug in a persistent pool: unique slug = cache miss
        $slug = 'optional-fields-'.uniqid();

        $row = [
            'title' => 'Test article',
            'shortDescription' => 'Short description',
            'slug' => $slug,
            'body' => $body,
            '_createdAt' => '2026-01-01T00:00:00Z',
        ];

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            static fn (string $query, array $params = []): mixed => str_contains($query, 'body[]') ? $row : []
        );
        static::getContainer()->set(SanityService::class, $sanity);

        $client->request('GET', '/fr/blog/'.$slug);

        return $client;
    }

    public function testItRendersWysiwygBlockWithoutBackgroundField(): void
    {
        $client = $this->requestPostWithBody([[
            '_type' => 'wysiwygBlock',
            '_key' => 'w1',
            'title' => 'Section sans fond',
            'content' => [[
                '_type' => 'block',
                '_key' => 'b1',
                'style' => 'normal',
                'markDefs' => [],
                'children' => [['_type' => 'span', '_key' => 's1', 'text' => 'Texte de la section', 'marks' => []]],
            ]],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#section-sans-fond h2', 'Section sans fond');
        self::assertSelectorTextContains('#section-sans-fond', 'Texte de la section');
    }

    public function testItRendersBlocksWithOnlyTypeAndKey(): void
    {
        $client = $this->requestPostWithBody([
            ['_type' => 'wysiwygBlock', '_key' => 'w1'],
            ['_type' => 'faqBlock', '_key' => 'f1'],
            ['_type' => 'quickAnswerBlock', '_key' => 'q1'],
            ['_type' => 'ctaBlock', '_key' => 'c1'],
        ]);

        self::assertResponseIsSuccessful();
    }
}
