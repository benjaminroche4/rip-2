<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierRecap;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * On-demand AI quick recap of a dossier, built from the data already in
 * the database (persons, search criteria, follow-up notes, status). The
 * result is persisted on the dossier: the LLM is never called on page
 * load, only when an admin explicitly asks for a (re)generation.
 *
 * Strict-JSON prompting instead of provider-specific structured output:
 * works identically across bridges and stays trivial to fake in tests.
 */
final readonly class DossierRecapGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Tu es l'assistant interne du back-office de Relocation in Paris,
        une agence de relocation à Paris. On te donne les données brutes
        d'un dossier client (locataires, critères de recherche de logement,
        notes de suivi de l'équipe, statut). Rédige un récapitulatif
        opérationnel pour un agent qui reprend le dossier.

        Règles :
        - Réponds UNIQUEMENT avec un objet JSON valide, sans balises de code,
          au format exact : {"summary": string, "attentionPoints": string[], "nextAction": string|null}
        - "summary" : 2 à 4 phrases en français, factuelles, qui situent le
          dossier (qui, quoi, où en est-on).
        - "attentionPoints" : 0 à 4 points courts sur ce qui bloque ou mérite
          vigilance (pièces manquantes, échéance proche, budget serré...).
        - "nextAction" : la prochaine action concrète recommandée, ou null.
        - La première ligne des données donne la date du jour : tout délai ou
          échéance (installation, ancienneté du dossier) se calcule par rapport
          à elle, jamais par rapport à la date de création. Une date
          d'installation déjà passée se signale comme dépassée, pas comme un
          délai restant.
        - N'invente aucune information absente des données. Pas de tiret
          cadratin. Vouvoie personne : style télégraphique professionnel.
        PROMPT;

    public function __construct(
        #[Autowire(service: 'ai.agent.dossier_recap')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?TranslatorInterface $translator = null,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Generates, persists and returns the recap; null when the model is
     * unreachable or answers garbage (the caller shows a soft error, the
     * page must never break on an LLM hiccup).
     */
    public function generate(Dossier $dossier): ?DossierRecap
    {
        try {
            $result = $this->agent->call(new MessageBag(
                Message::forSystem(self::SYSTEM_PROMPT),
                Message::ofUser($this->buildContext($dossier)),
            ));
            $content = (string) $result->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Dossier recap generation failed: '.$e->getMessage(), [
                'reference' => $dossier->getReference(),
            ]);

            return null;
        }

        // Models occasionally wrap JSON in code fences despite instructions.
        $json = trim($content);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $json) ?? $json;

        $recap = DossierRecap::fromJson($json);
        if (null === $recap) {
            $this->logger->error('Dossier recap answer was not the expected JSON.', [
                'reference' => $dossier->getReference(),
                'answer' => mb_substr($content, 0, 300),
            ]);

            return null;
        }

        $generatedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        $dossier->setRecap($recap->toJson(), $generatedAt);
        $this->em->flush();

        return new DossierRecap($recap->summary, $recap->attentionPoints, $recap->nextAction, $generatedAt);
    }

    /**
     * Compact plain-text snapshot of the dossier. Only business data the
     * summary needs: no IP, no email verbatim dumps, no file contents.
     */
    private function buildContext(Dossier $dossier): string
    {
        // French label rather than the raw enum value: the model quotes the
        // status verbatim in its summary.
        $status = $dossier->getEffectiveStatus();
        $lines = [
            // Le modèle n'a pas d'horloge : sans cette ligne, il raisonne
            // comme au jour de création (délais restants faux, échéances
            // dépassées annoncées comme à venir).
            'Date du jour : '.$this->clock->now()->format('d/m/Y'),
            'Dossier : '.$dossier->getName().' ('.$dossier->getReference().')',
            'Créé le : '.$dossier->getCreatedAt()?->format('d/m/Y'),
            'Statut : '.($this->translator?->trans($status->labelKey(), locale: 'fr') ?? $status->value),
        ];
        if (null !== $dossier->getClosedAt()) {
            $lines[] = 'Clôturé le : '.$dossier->getClosedAt()->format('d/m/Y');
        }

        foreach ($dossier->getPersons() as $person) {
            $parts = array_filter([
                trim(((string) $person->getFirstName()).' '.((string) $person->getLastName())),
                $person->getRole()?->value,
                $person->isPrimaryContact() ? 'contact principal' : null,
                $person->getProfession(),
                null !== $person->getMonthlyIncome() ? 'revenus '.$person->getMonthlyIncome().' €/mois' : null,
                $person->getNationality(),
            ]);
            $lines[] = 'Personne : '.implode(', ', $parts);
        }

        $search = $dossier->getSearch();
        if (null !== $search) {
            $criteria = array_filter([
                null !== $search->getBudget() ? 'budget '.$search->getBudget().' €/mois' : null,
                null !== $search->getMoveInAt() ? 'installation souhaitée le '.$search->getMoveInAt()->format('d/m/Y') : null,
                $search->getPropertyType(),
                $search->getAreas() ? 'quartiers '.$search->getAreas() : null,
                $search->getStayDuration(),
                $search->getFurnishing(),
                [] !== $search->getGuarantorTypes() ? 'garant '.implode(', ', $search->getGuarantorTypes()) : null,
            ]);
            if ([] !== $criteria) {
                $lines[] = 'Recherche : '.implode(', ', $criteria);
            }
            if (null !== $search->getNote() && '' !== trim($search->getNote())) {
                $lines[] = 'Note recherche : '.trim($search->getNote());
            }
        }

        foreach ($dossier->getNotes() as $note) {
            $lines[] = \sprintf(
                'Note (%s, %s) : %s',
                $note->getCreatedAt()->format('d/m/Y'),
                $note->getAuthorName(),
                trim($note->getText()),
            );
        }

        return implode("\n", $lines);
    }
}
