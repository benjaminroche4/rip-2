<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guard against the whole class of bug where a constraint message shows up
 * as its raw key to the user. Symfony translates violation messages in the
 * `validators` domain, so a message written only in `messages.*.yaml` is
 * never resolved and the key leaks to the screen. Functional tests do not
 * catch it: they assert the 422, never the wording.
 *
 * This scans the source for constraint message keys and checks each one
 * resolves in French *and* English.
 */
final class ValidationMessagesAreTranslatedTest extends KernelTestCase
{
    private const DOMAIN = 'validators';

    public function testEveryConstraintMessageResolvesInBothLocales(): void
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $keys = $this->constraintMessageKeys();
        self::assertGreaterThan(50, \count($keys), 'The scanner found suspiciously few constraint messages.');

        $unresolved = [];
        foreach ($keys as $key) {
            foreach (['fr', 'en'] as $locale) {
                if ($translator->trans($key, [], self::DOMAIN, $locale) === $key) {
                    $unresolved[] = $key.' ('.$locale.')';
                }
            }
        }

        self::assertSame([], $unresolved, \sprintf(
            "These constraint messages are not translated in the \"%s\" domain and would be shown raw to the user:\n  - %s",
            self::DOMAIN,
            implode("\n  - ", $unresolved),
        ));
    }

    /**
     * @return list<string>
     */
    private function constraintMessageKeys(): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)
        );

        $keys = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/(?:(?:message|maxMessage|minMessage|invalidMessage|notInRangeMessage|mimeTypesMessage|maxSizeMessage)\s*:\s*|(?:buildViolation|addViolation)\s*\(\s*)\'([^\']+)\'/',
                $source,
                $matches
            );
            foreach ($matches[1] as $key) {
                // Concatenated keys ('prefix.'.$var) and plain sentences are
                // out of scope: only complete dotted keys are checkable.
                if (!str_contains($key, '.') || str_ends_with($key, '.')) {
                    continue;
                }
                $keys[$key] = true;
            }
        }

        ksort($keys);

        return array_keys($keys);
    }
}
