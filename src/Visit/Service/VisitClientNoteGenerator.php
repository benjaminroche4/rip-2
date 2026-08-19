<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Visit\Entity\Visit;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * AI draft of the note sent to the client after their visit, built from
 * the property data stored on the visit and the agent's internal feedback.
 * Called only on explicit demand (button on the report card), never on
 * page load; the caller persists the returned text on the visit.
 */
final readonly class VisitClientNoteGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Tu rédiges, pour un conseiller de Relocation in Paris (agence de
        relocation à Paris), le message envoyé à son client après la visite
        d'un logement. On te donne les données du bien et le retour interne
        du conseiller.

        Règles :
        - Réponds UNIQUEMENT avec le texte du message, sans objet, sans
          signature, sans balises.
        - 3 à 6 phrases en français, vouvoiement, ton professionnel et
          chaleureux, adressé directement au client.
        - Récapitule l'essentiel du bien visité (adresse, points clés) et ce
          qui ressort de la visite, puis la suite proposée.
        - Le retour interne sert de matière : reformule, ne le cite jamais
          tel quel et n'expose aucun jugement interne (ressenti chiffré,
          stratégie, réserves confidentielles).
        - N'invente aucune information absente des données. Pas de tiret
          cadratin.
        PROMPT;

    public function __construct(
        #[Autowire(service: 'ai.agent.visit_client_note')]
        private AgentInterface $agent,
        private VisitPropertyRecap $propertyRecap,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the drafted note, or null when the model is unreachable or
     * answers garbage (the caller shows a soft error, never a 500).
     */
    public function generate(Visit $visit): ?string
    {
        try {
            $result = $this->agent->call(new MessageBag(
                Message::forSystem(self::SYSTEM_PROMPT),
                Message::ofUser($this->buildContext($visit)),
            ));
            $content = trim((string) $result->getContent());
        } catch (\Throwable $e) {
            $this->logger->error('Visit client note generation failed: '.$e->getMessage(), [
                'reference' => $visit->getReference(),
            ]);

            return null;
        }

        return '' !== $content ? $content : null;
    }

    private function buildContext(Visit $visit): string
    {
        $described = $this->propertyRecap->describe($visit);
        $lines = [
            'Adresse du bien : '.$visit->getAddress(),
            'Date de la visite : '.$visit->getScheduledAt()?->format('d/m/Y H\hi'),
        ];
        if ([] !== $described['facts']) {
            $lines[] = 'Caractéristiques : '.implode(' · ', $described['facts']);
        }
        if (null !== $described['rent']) {
            $lines[] = 'Loyer : '.$described['rent'];
        }
        if (null !== $visit->getNote() && '' !== trim($visit->getNote())) {
            $lines[] = 'Note logistique de la visite : '.trim($visit->getNote());
        }
        if (null !== $visit->getReport() && '' !== trim($visit->getReport())) {
            $lines[] = 'Retour interne du conseiller (matière à reformuler, jamais à citer) : '.trim($visit->getReport());
        }
        if (null !== $visit->getClientFeeling()) {
            $lines[] = 'Ressenti interne du client pendant la visite : '.$visit->getClientFeeling()->value;
        }

        return implode("\n", $lines);
    }
}
