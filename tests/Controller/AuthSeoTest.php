<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEO guard for auth pages: they must stay noindex and must NOT carry a
 * canonical link (contradictory signal on a noindex page).
 */
final class AuthSeoTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function authPathProvider(): iterable
    {
        yield 'login fr' => ['/fr/connexion'];
        yield 'login en' => ['/en/login'];
        yield 'register fr' => ['/fr/inscription'];
        yield 'forgot password fr' => ['/fr/reinitialiser-mot-de-passe'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authPathProvider')]
    public function testItServesAuthPagesAsNoindexWithoutCanonical(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(0, $crawler->filter('link[rel="canonical"]')->count());
    }
}
