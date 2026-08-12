<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * AI-generated quick recap of a dossier, persisted as JSON alongside the
 * dossier so the LLM is only called on demand, never on page load.
 */
final readonly class DossierRecap
{
    /**
     * @param list<string> $attentionPoints
     */
    public function __construct(
        public string $summary,
        public array $attentionPoints,
        public ?string $nextAction,
        public ?\DateTimeImmutable $generatedAt = null,
    ) {
    }

    public static function fromJson(string $json, ?\DateTimeImmutable $generatedAt = null): ?self
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || !\is_string($data['summary'] ?? null) || '' === trim($data['summary'])) {
            return null;
        }

        $points = [];
        foreach (\is_array($data['attentionPoints'] ?? null) ? $data['attentionPoints'] : [] as $point) {
            if (\is_string($point) && '' !== trim($point)) {
                $points[] = trim($point);
            }
        }

        $nextAction = $data['nextAction'] ?? null;

        return new self(
            summary: trim($data['summary']),
            attentionPoints: $points,
            nextAction: \is_string($nextAction) && '' !== trim($nextAction) ? trim($nextAction) : null,
            generatedAt: $generatedAt,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'summary' => $this->summary,
            'attentionPoints' => $this->attentionPoints,
            'nextAction' => $this->nextAction,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
