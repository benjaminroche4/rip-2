<?php

declare(strict_types=1);

namespace App\Marketing\Twig;

use App\Marketing\Domain\FaqCategory;
use App\Marketing\Service\FaqCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FaqExtension extends AbstractExtension
{
    public function __construct(
        private readonly FaqCatalog $faqCatalog,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('faq_question_count', $this->questionCount(...)),
        ];
    }

    /** Number of questions on the FAQ page: follows the catalog, never hardcoded. */
    public function questionCount(): int
    {
        return array_sum(array_map(static fn (FaqCategory $category): int => \count($category->entries), $this->faqCatalog->categories()));
    }
}
