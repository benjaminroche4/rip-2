<?php

namespace App\Tests\Auth\Service;

use App\Auth\Service\UserSlugger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class UserSluggerTest extends TestCase
{
    private UserSlugger $slugger;

    protected function setUp(): void
    {
        $this->slugger = new UserSlugger(new AsciiSlugger());
    }

    public function testStripsAccentsAndLowercases(): void
    {
        self::assertSame('emilie-dupre', $this->slugger->slug('Émilie', 'Dupré', 'emilie@example.com'));
    }

    public function testHandlesApostropheAsSeparator(): void
    {
        self::assertSame('jean-d-arc', $this->slugger->slug('Jean', "d'Arc", 'jean@example.com'));
    }

    public function testFallsBackToEmailLocalPartWhenNameIsEmpty(): void
    {
        self::assertSame('alice', $this->slugger->slug('', '', 'alice@example.com'));
    }

    public function testFallsBackToUserWhenEverythingIsEmpty(): void
    {
        self::assertSame('user', $this->slugger->slug('', '', ''));
    }

    public function testTrimsWhitespaceOnlyName(): void
    {
        // Whitespace-only name → fallback to email local-part, not "  ".
        self::assertSame('bob', $this->slugger->slug('  ', '  ', 'bob@example.com'));
    }
}
