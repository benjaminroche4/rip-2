<?php

namespace App\Tests\Shared\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * SEO guard: page titles are rendered as "Relocation In Paris - <meta.*.title>"
 * (22-char prefix) and must stay within Google's ~60-char display limit;
 * meta descriptions must stay under 160 chars to avoid truncation in SERPs.
 */
final class MetaLengthTest extends TestCase
{
    private const TITLE_PREFIX_LENGTH = 22; // "Relocation In Paris - "
    private const TITLE_MAX = 60;
    private const DESCRIPTION_MAX = 160;

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testItKeepsMetaTitlesAndDescriptionsWithinSeoLimits(string $locale): void
    {
        $messages = Yaml::parseFile(\dirname(__DIR__, 3).'/translations/messages.'.$locale.'.yaml');
        $violations = [];

        foreach ($this->flatten($messages['meta'] ?? []) as $key => $value) {
            $length = mb_strlen($value);
            if (str_ends_with($key, 'title') && $length + self::TITLE_PREFIX_LENGTH > self::TITLE_MAX) {
                $violations[] = sprintf('meta.%s: full title is %d chars (max %d)', $key, $length + self::TITLE_PREFIX_LENGTH, self::TITLE_MAX);
            }
            if (str_ends_with($key, 'description') && $length > self::DESCRIPTION_MAX) {
                $violations[] = sprintf('meta.%s: description is %d chars (max %d)', $key, $length, self::DESCRIPTION_MAX);
            }
        }

        self::assertSame([], $violations, sprintf("[%s] SEO meta length violations:\n%s", $locale, implode("\n", $violations)));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}
