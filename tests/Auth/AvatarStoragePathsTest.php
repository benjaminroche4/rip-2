<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Storage\LocalAvatarStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bucket key convention of the avatar storage: {domain}/{ref}/{type}/{uuid}.webp,
 * with a strict whitelist of domains/types (users, agencies, agents).
 */
final class AvatarStoragePathsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/avatar-paths-'.bin2hex(random_bytes(4));
    }

    public function testDefaultsKeepTheUserLayout(): void
    {
        $storage = new LocalAvatarStorage($this->dir, new NullLogger());
        $path = $storage->store('01ABC', 'webp-bytes');

        self::assertMatchesRegularExpression('#^users/01ABC/avatar/[0-9a-f-]{36}\\.webp$#', $path);
    }

    public function testAgencyLogoAndAgentAvatarFollowTheConvention(): void
    {
        $storage = new LocalAvatarStorage($this->dir, new NullLogger());

        self::assertMatchesRegularExpression('#^agencies/12/logo/[0-9a-f-]{36}\\.webp$#', $storage->store('12', 'x', 'agencies', 'logo'));
        self::assertMatchesRegularExpression('#^agents/7/avatar/[0-9a-f-]{36}\\.webp$#', $storage->store('7', 'x', 'agents', 'avatar'));
    }

    public function testUnknownSegmentsAreRejected(): void
    {
        $storage = new LocalAvatarStorage($this->dir, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $storage->store('7', 'x', 'listings', 'logo');
    }
}
