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
        - Court et rapide à lire : 2 à 4 phrases simples en français,
          vouvoiement, adressé directement au client.
        - Ton naturel et vivant, comme un conseiller qui écrit à son client
          après la visite : jamais de formules corporate ni de tournures
          d'annonce immobilière.
        - Donne un avis sur le logement, et cet avis est TOUJOURS positif :
          appuie-toi sur tout ce qu'on te donne (caractéristiques du bien,
          loyer, atouts cochés, ressenti global, retour du conseiller) pour
          mettre en avant ce qui donne envie d'y vivre. Ne mentionne jamais
          un défaut, une réserve ou un point négatif : s'il y en a dans le
          retour interne, ignore-les.
        - Le retour interne sert de matière : reformule, ne le cite jamais
          tel quel et n'expose aucun jugement interne (ressenti chiffré,
          stratégie, réserves confidentielles).
        - N'invente aucune information absente des données. Pas de tiret
          cadratin.
        PROMPT;

    private const TRANSLATE_SYSTEM_PROMPT = <<<'PROMPT'
        Tu traduis en anglais le message qu'un conseiller de Relocation in
        Paris (agence de relocation à Paris) envoie à son client après la
        visite d'un logement.

        Règles :
        - Réponds UNIQUEMENT avec la traduction, sans balises, sans
          commentaire, sans objet ni signature.
        - Traduction fidèle : même contenu, même découpage en phrases,
          aucune information ajoutée ni retirée.
        - Anglais naturel, même ton chaleureux et simple que l'original :
          un conseiller qui écrit à son client, jamais du mot à mot ni des
          formules corporate.
        - Pas de tiret cadratin.
        PROMPT;

    public function __construct(
        #[Autowire(service: 'ai.agent.visit_client_note')]
        private AgentInterface $agent,
        private VisitPropertyRecap $propertyRecap,
        private LoggerInterface $logger,
        private \Symfony\Contracts\Translation\TranslatorInterface $translator,
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

    /**
     * English translation of the final client note, produced at send time
     * for English-speaking recipients. Returns null when the model is
     * unreachable, answers garbage, or the note is blank (the caller falls
     * back to the French text, the email still goes out).
     */
    public function translateToEnglish(string $note): ?string
    {
        $note = trim($note);
        if ('' === $note) {
            return null;
        }

        try {
            $result = $this->agent->call(new MessageBag(
                Message::forSystem(self::TRANSLATE_SYSTEM_PROMPT),
                Message::ofUser($note),
            ));
            $content = trim((string) $result->getContent());
        } catch (\Throwable $e) {
            $this->logger->error('Visit client note translation failed: '.$e->getMessage());

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
        if ([] !== $visit->getReportHighlights()) {
            // Les tags positifs cochés par le conseiller : de la matière
            // sûre (jamais un jugement interne), donnée en clair au modèle.
            $lines[] = 'Les plus du logement cochés par le conseiller : '.implode(', ', array_map(
                fn (\App\Visit\Domain\PropertyHighlight $highlight): string => $this->translator->trans($highlight->labelKey(), locale: 'fr'),
                $visit->getReportHighlights(),
            ));
        }
        if (null !== $visit->getClientFeeling()) {
            $lines[] = 'Ressenti interne du client pendant la visite : '.$visit->getClientFeeling()->value;
        }

        return implode("\n", $lines);
    }
}
