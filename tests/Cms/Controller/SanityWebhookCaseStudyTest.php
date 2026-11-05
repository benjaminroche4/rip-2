<?php

namespace App\Tests\Cms\Controller;

use App\CaseStudy\Repository\SanityCaseStudyRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class SanityWebhookCaseStudyTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function caseStudyTypes(): iterable
    {
        yield 'a case study' => ['caseStudy'];
        yield 'an agent (shown on case studies)' => ['agent'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('caseStudyTypes')]
    public function testItRefreshesTheCaseStudiesWhenTheirContentChanges(string $type): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var TagAwareCacheInterface $cache */
        $cache = static::getContainer()->get(TagAwareCacheInterface::class);
        $cache->get('case_studies_fr', static function (ItemInterface $item): array {
            $item->tag(SanityCaseStudyRepository::CACHE_TAG);

            return ['cached'];
        });

        $payload = json_encode(['_type' => $type], \JSON_THROW_ON_ERROR);
        $client->request('POST', '/_webhook/sanity', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SANITY_WEBHOOK_SIGNATURE' => self::sign($payload),
        ], content: $payload);

        self::assertResponseIsSuccessful();
        self::assertContains(SanityCaseStudyRepository::CACHE_TAG, json_decode((string) $client->getResponse()->getContent(), true)['invalidated']);
        $recomputed = $cache->get('case_studies_fr', static fn (): array => ['fresh']);
        self::assertSame(['fresh'], $recomputed);
    }

    public function testItRejectsAnUnsignedPayload(): void
    {
        $client = static::createClient();
        $client->request('POST', '/_webhook/sanity', server: ['CONTENT_TYPE' => 'application/json'], content: '{"_type":"caseStudy"}');

        self::assertResponseStatusCodeSame(401);
    }

    private static function sign(string $payload): string
    {
        $timestamp = (string) time();
        // The secret the app actually loaded (.env.test, or a developer's .env.test.local)
        $secret = (string) ($_SERVER['WEBHOOK_SANITY_SECRET'] ?? $_ENV['WEBHOOK_SANITY_SECRET'] ?? '');
        $hash = rtrim(strtr(base64_encode(hash_hmac('sha256', $timestamp.'.'.$payload, $secret, true)), '+/', '-_'), '=');

        return 't='.$timestamp.',v1='.$hash;
    }
}
