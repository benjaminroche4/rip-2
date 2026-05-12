<?php

declare(strict_types=1);

namespace App\Tests\Admin\Factory;

use App\Admin\Domain\DocumentCategory;
use App\Admin\Entity\Document;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Document>
 */
final class DocumentFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Document::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        $words = self::faker()->words(3);
        $base = strtolower(implode('-', $words));

        return [
            // unique() avoids slug collisions across createMany() since the
            // unique constraint at the DB level would otherwise blow up the
            // fixtures load on a borderline-duplicate faker draw.
            'slug' => self::faker()->unique()->regexify('[a-z0-9]{6,12}').'-'.$base,
            'nameFr' => ucfirst(self::faker()->sentence(3, false)),
            'nameEn' => ucfirst(self::faker()->sentence(3, false)),
            'descriptionFr' => self::faker()->optional(0.7)->paragraph(),
            'descriptionEn' => self::faker()->optional(0.7)->paragraph(),
            'category' => self::faker()->randomElement(DocumentCategory::cases()),
            'pinned' => self::faker()->boolean(20),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
