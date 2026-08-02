<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Full-page cache contract for o2switch's LiteSpeed: anonymous public
 * pages are cacheable and tagged, everything else is explicitly no-cache
 * (LiteSpeed ignores the standard "private" directive).
 */
final class LiteSpeedCacheTest extends WebTestCase
{
    public function testAnonymousPublicPageIsCacheableAndTagged(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/tarifs');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-LiteSpeed-Cache-Control', 'public, max-age=3600');
        self::assertResponseHeaderSame('X-LiteSpeed-Tag', 'static');
    }

    public function testBlogUsesItsOwnTag(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/blog');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-LiteSpeed-Tag', 'blog');
    }

    public function testNonWhitelistedPageIsExplicitlyNoCache(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/connexion');

        self::assertResponseHeaderSame('X-LiteSpeed-Cache-Control', 'no-cache');
    }

    public function testAuthenticatedUserNeverFeedsThePublicCache(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')->setParameter('e', 'lscache@test.local')->execute();
        $user = (new User())
            ->setEmail('lscache@test.local')
            ->setFirstName('Cache')->setLastName('Test')
            ->setRoles(['ROLE_USER'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/fr/tarifs');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-LiteSpeed-Cache-Control', 'no-cache');
    }

    public function testPurgeEndpointIsDisabledWithoutToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_cache/purge?token=whatever');

        self::assertResponseStatusCodeSame(404);
    }
}
