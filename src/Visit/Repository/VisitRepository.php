<?php

declare(strict_types=1);

namespace App\Visit\Repository;

use App\Visit\Domain\VisitStatus;
use App\Visit\Domain\VisitSummary;
use App\Visit\Entity\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Visit>
 */
class VisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visit::class);
    }

    public function findOneSummary(int $id): ?VisitSummary
    {
        $visit = $this->find($id);

        return null !== $visit ? $this->toSummary($visit) : null;
    }

    /**
     * Lookup by public reference ("VS-087526"): the admin URLs are keyed on
     * it, never on the auto-increment id (same model as agents/dossiers).
     */
    public function findOneSummaryByReference(string $reference): ?VisitSummary
    {
        $visit = $this->findOneBy(['reference' => $reference]);

        return null !== $visit ? $this->toSummary($visit) : null;
    }

    public function countOnDay(\DateTimeImmutable $day): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.scheduledAt >= :start')
            ->andWhere('v.scheduledAt < :end')
            ->setParameter('start', $day->setTime(0, 0))
            ->setParameter('end', $day->setTime(0, 0)->modify('+1 day'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Archive: visits before the start of the current day, most recent
     * first. Days auto-archive at midnight, no manual action involved; the
     * limit feeds the "see more" pagination.
     *
     * @return list<VisitSummary>
     */
    public function findArchivedSummaries(\DateTimeImmutable $now, ?int $limit = null, ?string $postStatus = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('v.scheduledAt < :start')
            ->setParameter('start', $now->setTime(0, 0))
            ->orderBy('v.scheduledAt', 'DESC');
        $this->applyPostStatus($qb, $postStatus);
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Visit> $visits */
        $visits = $qb->getQuery()->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    public function countUpcoming(\DateTimeImmutable $now): int
    {
        // Une visite annulée n'est pas "à venir" : elle sort du compteur
        // (l'archive, elle, liste bien les annulées).
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.scheduledAt >= :start')
            ->andWhere('v.status != :cancelled')
            ->setParameter('start', $now->setTime(0, 0))
            ->setParameter('cancelled', VisitStatus::Cancelled)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Visit counters for the dossiers list, in one grouped query so the card
     * loop never triggers a query per dossier. Cancelled visits are ignored:
     * a visit the agent called off never happened, it must not inflate the
     * "too many visits" warning colour.
     *
     * @param list<int> $dossierIds
     *
     * @return array<int, array{upcoming: int, total: int}>
     */
    public function countsByDossier(array $dossierIds, \DateTimeImmutable $now): array
    {
        if ([] === $dossierIds) {
            return [];
        }

        /** @var list<array{dossierId: int, total: int|string, upcoming: int|string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.dossier) AS dossierId')
            ->addSelect('COUNT(v.id) AS total')
            ->addSelect('SUM(CASE WHEN v.scheduledAt >= :start THEN 1 ELSE 0 END) AS upcoming')
            ->where('v.dossier IN (:dossiers)')
            ->andWhere('v.status != :cancelled')
            ->setParameter('dossiers', $dossierIds)
            ->setParameter('cancelled', VisitStatus::Cancelled)
            ->setParameter('start', $now->setTime(0, 0))
            ->groupBy('v.dossier')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['dossierId']] = [
                'upcoming' => (int) $row['upcoming'],
                'total' => (int) $row['total'],
            ];
        }

        return $counts;
    }

    public function countArchived(\DateTimeImmutable $now, ?string $postStatus = null): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.scheduledAt < :start')
            ->setParameter('start', $now->setTime(0, 0));
        $this->applyPostStatus($qb, $postStatus);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Filtre "statut post-visite" de l'archive : compte-rendu attendu,
     * décision du client, ou issue de la candidature. Une valeur inconnue
     * est ignorée (aucun filtre), jamais passée telle quelle en SQL.
     */
    private function applyPostStatus(\Doctrine\ORM\QueryBuilder $qb, ?string $postStatus): void
    {
        if (null === $postStatus || '' === $postStatus) {
            return;
        }
        if ('report_due' === $postStatus) {
            $qb->andWhere("v.status = :psPlanned OR (v.status = :psDone AND (v.report IS NULL OR TRIM(v.report) = ''))")
                ->setParameter('psPlanned', VisitStatus::Planned)
                ->setParameter('psDone', VisitStatus::Done);

            return;
        }
        if (null !== ($decision = \App\Visit\Domain\ClientDecision::tryFrom($postStatus))) {
            $qb->andWhere('v.clientDecision = :psDecision')->setParameter('psDecision', $decision);

            return;
        }
        if ('accepted' === $postStatus || 'outcome_refused' === $postStatus) {
            $qb->andWhere('v.applicationOutcome = :psOutcome')
                ->setParameter('psOutcome', 'accepted' === $postStatus ? \App\Visit\Domain\ApplicationOutcome::Accepted : \App\Visit\Domain\ApplicationOutcome::Refused);
        }
    }

    /**
     * Every visit booked for one dossier, chronological: feeds the visit
     * module card on the dossier detail page.
     *
     * @return list<VisitSummary>
     */
    public function findByDossierSummaries(int $dossierId): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('d.id = :dossier')
            ->setParameter('dossier', $dossierId)
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    /**
     * Visits carried out with a given directory agent (the "agent
     * immobilier" picked on the visit), chronological; the agent detail
     * page splits them into upcoming and past.
     *
     * @return list<VisitSummary>
     */
    public function findByRealEstateAgentSummaries(int $agentId): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('a.id = :agent')
            ->setParameter('agent', $agentId)
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    /**
     * Read model for the visits page: every visit from the start of the given
     * day (Europe/Paris) onwards, chronological.
     *
     * @return list<VisitSummary>
     */
    public function findUpcomingSummaries(\DateTimeImmutable $from): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')
            ->addSelect('d')
            ->leftJoin('v.agent', 'a')
            ->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')
            ->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')
            ->addSelect('b')
            ->leftJoin('v.photos', 'p')
            ->addSelect('p')
            ->where('v.scheduledAt >= :from')
            ->setParameter('from', $from->setTime(0, 0))
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    /**
     * Visits already booked on the same property: same address, or same
     * listing link. Feeds the duplicate warning of the booking form (create
     * and edit), so two agents never travel to the same door twice.
     *
     * The address is compared on its normalised form (the Places autocomplete
     * writes the same formatted string every time); the listing link is
     * compared on its canonical form (scheme, "www." and trailing slash
     * dropped) so the same ad pasted twice always matches.
     *
     * @return list<VisitSummary>
     */
    public function findMatchingSummaries(string $address, ?string $listingUrl, ?int $excludeId = null, int $limit = 5): array
    {
        $address = self::normalizeAddress($address);
        $urlVariants = self::listingUrlVariants($listingUrl);
        if ('' === $address && [] === $urlVariants) {
            return [];
        }

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->orderBy('v.scheduledAt', 'ASC')
            ->setMaxResults($limit);

        $matches = $qb->expr()->orX();
        if ('' !== $address) {
            $matches->add('LOWER(TRIM(v.address)) = :address');
            $qb->setParameter('address', $address);
        }
        if ([] !== $urlVariants) {
            $matches->add('LOWER(TRIM(v.listingUrl)) IN (:urls)');
            $qb->setParameter('urls', $urlVariants);
        }
        $qb->where($matches);

        if (null !== $excludeId) {
            $qb->andWhere('v.id != :self')->setParameter('self', $excludeId);
        }

        /** @var list<Visit> $visits */
        $visits = $qb->getQuery()->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    private static function normalizeAddress(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * Stored links are raw: match against the plausible spellings of the same
     * ad rather than normalising the column.
     *
     * @return list<string>
     */
    private static function listingUrlVariants(?string $listingUrl): array
    {
        $url = mb_strtolower(trim((string) $listingUrl));
        if ('' === $url) {
            return [];
        }

        $bare = preg_replace('#^https?://#', '', $url) ?? $url;
        $bare = preg_replace('#^www\.#', '', $bare) ?? $bare;
        $bare = rtrim($bare, '/');
        if ('' === $bare) {
            return [];
        }

        $variants = [];
        foreach (['', 'www.'] as $host) {
            foreach (['https://', 'http://', ''] as $scheme) {
                $variants[] = $scheme.$host.$bare;
                $variants[] = $scheme.$host.$bare.'/';
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Visites non annulées du même assigné qui chevauchent le créneau
     * [start, start + duration] : nourrit le bandeau "conflit d'agenda" du
     * formulaire de réservation. Le chevauchement dépend de la durée de
     * chaque visite existante (stockée en base), donc il se calcule en PHP
     * après un préfiltre SQL sur les journées touchées par l'intervalle.
     *
     * @return list<VisitSummary> au plus 3, chronologiques
     */
    public function findAssigneeConflicts(int $assigneeId, \DateTimeImmutable $start, int $durationMinutes, ?int $excludeId = null): array
    {
        $end = $start->modify(sprintf('+%d minutes', max(1, $durationMinutes)));

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('a.agency', 'ag')->addSelect('ag')
            ->leftJoin('ag.brand', 'br')->addSelect('br')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('u.id = :assignee')
            ->andWhere('v.status != :cancelled')
            ->andWhere('v.scheduledAt >= :dayStart AND v.scheduledAt < :dayEnd')
            ->setParameter('assignee', $assigneeId)
            ->setParameter('cancelled', VisitStatus::Cancelled)
            // La veille est incluse dans le préfiltre : une visite qui
            // démarre avant minuit et déborde sur le jour du créneau (23h30
            // + 60 min vs 00h15) serait sinon ratée; le vrai chevauchement
            // se rejoue de toute façon en PHP juste en dessous.
            ->setParameter('dayStart', $start->setTime(0, 0)->modify('-1 day'))
            ->setParameter('dayEnd', $end->setTime(0, 0)->modify('+1 day'))
            ->orderBy('v.scheduledAt', 'ASC');
        if (null !== $excludeId) {
            $qb->andWhere('v.id != :self')->setParameter('self', $excludeId);
        }

        /** @var list<Visit> $visits */
        $visits = $qb->getQuery()->getResult();

        $conflicts = [];
        foreach ($visits as $visit) {
            $existingStart = $visit->getScheduledAt();
            if (null === $existingStart) {
                continue;
            }
            $existingEnd = $existingStart->modify(sprintf('+%d minutes', max(1, $visit->getDurationMinutes())));
            // Vrai chevauchement : débutA < finB et débutB < finA. Deux
            // visites dos à dos (14h-14h30 puis 14h30-15h) ne se gênent pas.
            if ($existingStart < $end && $start < $existingEnd) {
                $conflicts[] = $this->toSummary($visit);
                if (3 === \count($conflicts)) {
                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Vrai double-booking : le même dossier a déjà une visite (non annulée)
     * à la même adresse le même jour. Bloque la création; une contre-visite
     * un autre jour reste possible.
     */
    public function hasSameDossierSameDayVisit(int $dossierId, string $address, \DateTimeImmutable $scheduledAt, ?int $excludeId = null): bool
    {
        $address = self::normalizeAddress($address);
        if ('' === $address) {
            return false;
        }
        $dayStart = $scheduledAt->setTime(0, 0);

        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.dossier = :dossier')
            ->andWhere('LOWER(TRIM(v.address)) = :address')
            ->andWhere('v.scheduledAt >= :dayStart AND v.scheduledAt < :dayEnd')
            ->andWhere('v.status != :cancelled')
            ->setParameter('dossier', $dossierId)
            ->setParameter('address', $address)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayStart->modify('+1 day'))
            ->setParameter('cancelled', \App\Visit\Domain\VisitStatus::Cancelled);
        if (null !== $excludeId) {
            $qb->andWhere('v.id != :self')->setParameter('self', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Biens refusés par le client sur ce dossier (retour client "Refuse"),
     * pour le badge du module Visites de la fiche dossier. Calculé à chaque
     * rendu, jamais dénormalisé.
     */
    public function countRefusedByDossier(int $dossierId): int
    {
        // Seul le refus décidé PAR LE CLIENT compte (un refus du bailleur
        // ou une autre raison ne dit rien de l'exigence du client), et
        // uniquement sur une visite Effectuée : une visite annulée ou
        // ratée n'a jamais produit de retour client fondé (cohérent avec
        // countsByDossier : "une visite annulée n'a jamais eu lieu").
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.dossier = :dossier')
            ->andWhere('v.status = :done')
            ->andWhere('v.clientDecision = :refused')
            ->andWhere('v.refusalOrigin = :client')
            ->setParameter('dossier', $dossierId)
            ->setParameter('done', VisitStatus::Done)
            ->setParameter('refused', \App\Visit\Domain\ClientDecision::Refused)
            ->setParameter('client', \App\Visit\Domain\RefusalOrigin::Client)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Visites dont l'échéance de réflexion est atteinte ou dépassée (date
     * seule, heure de Paris) alors que le client réfléchit toujours, et dont
     * le rappel staff n'est jamais parti. Nourrit le cron
     * app:visits:send-decision-reminders, borné par le limit.
     *
     * @return list<Visit>
     */
    public function findDecisionRemindersDue(\DateTimeImmutable $today, int $limit): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->where('v.clientDecision = :thinking')
            ->andWhere('v.decisionDeadline IS NOT NULL')
            ->andWhere('v.decisionDeadline <= :today')
            ->andWhere('v.decisionReminderSentAt IS NULL')
            // Un rappel n'a de sens que pour une visite réellement effectuée
            // (une annulée/ratée n'a pas de retour client à relancer) sur un
            // dossier encore ouvert.
            ->andWhere('v.status = :done')
            ->andWhere('d.closedAt IS NULL')
            ->setParameter('thinking', \App\Visit\Domain\ClientDecision::Thinking)
            ->setParameter('done', VisitStatus::Done)
            ->setParameter('today', $today->setTime(0, 0))
            ->orderBy('v.decisionDeadline', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $visits;
    }

    /**
     * Visites effectuées depuis plus de 24 h dont le retour de l'agent est
     * toujours vide, rappel staff jamais parti, sur dossier encore ouvert.
     * Nourrit le cron quotidien app:visits:send-decision-reminders (volet
     * "compte-rendu à remplir"), borné par le limit. Faute d'horodatage de
     * transition, "effectuée depuis plus de 24 h" s'approxime sur le
     * créneau : scheduledAt à plus de 24 h (heure murale Paris).
     *
     * @return list<Visit>
     */
    public function findReportRemindersDue(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->where('v.status = :done')
            ->andWhere("v.report IS NULL OR TRIM(v.report) = ''")
            ->andWhere('v.reportReminderSentAt IS NULL')
            ->andWhere('v.scheduledAt <= :cutoff')
            ->andWhere('d.closedAt IS NULL')
            ->setParameter('done', VisitStatus::Done)
            ->setParameter('cutoff', $now->modify('-24 hours'))
            ->orderBy('v.scheduledAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $visits;
    }

    private function toSummary(Visit $visit): VisitSummary
    {
        $agent = $visit->getAgent();
        $agentName = null;
        $agentAgencyLine = null;
        if (null !== $agent) {
            $agentName = trim($agent->getFirstName().' '.$agent->getLastName());
            $agencyName = $agent->getAgency()?->getName();
            $brand = $agent->getAgency()?->getBrand()?->getName();
            $agentAgencyLine = null !== $agencyName && null !== $brand && $brand !== $agencyName
                ? $agencyName.' · '.$brand
                : $agencyName;
        }

        return new VisitSummary(
            id: (int) $visit->getId(),
            reference: (string) $visit->getReference(),
            // La colonne est NOT NULL : le repli ne sert qu'à satisfaire le
            // type (une entité hydratée porte toujours son créneau).
            scheduledAt: $visit->getScheduledAt() ?? new \DateTimeImmutable(),
            address: (string) $visit->getAddress(),
            latitude: $visit->getLatitude(),
            longitude: $visit->getLongitude(),
            dossierId: $visit->getDossier()?->getId(),
            dossierName: (string) $visit->getDossier()?->getName(),
            dossierReference: (string) $visit->getDossier()?->getReference(),
            agentName: $agentName,
            assigneeId: null !== $visit->getAssignee() ? (int) $visit->getAssignee()->getId() : null,
            assigneeName: null !== $visit->getAssignee()
                ? (trim(($visit->getAssignee()->getFirstName() ?? '').' '.($visit->getAssignee()->getLastName() ?? '')) ?: (string) $visit->getAssignee()->getEmail())
                : null,
            assigneeAvatar: $visit->getAssignee()?->getAvatarFilename(),
            bookedByAvatar: $visit->getBookedBy()?->getAvatarFilename(),
            bookedByName: null !== $visit->getBookedBy()
                ? (trim(($visit->getBookedBy()->getFirstName() ?? '').' '.($visit->getBookedBy()->getLastName() ?? '')) ?: (string) $visit->getBookedBy()->getEmail())
                : null,
            note: $visit->getNote(),
            type: $visit->getType(),
            status: $visit->getStatus(),
            listingUrl: $visit->getListingUrl(),
            durationMinutes: $visit->getDurationMinutes(),
            clientPresent: $visit->isClientPresent(),
            report: $visit->getReport(),
            clientNote: $visit->getClientNote(),
            clientNoteSentAt: $visit->getClientNoteSentAt(),
            clientFeeling: $visit->getClientFeeling(),
            firstPhotoId: false !== ($firstPhoto = $visit->getPhotos()->first()) ? (int) $firstPhoto->getId() : null,
            agentAvatar: $agent?->getAvatarFilename(),
            agentAgencyLine: $agentAgencyLine,
            agentReference: null !== $agent ? (string) $agent->getReference() : null,
            createdAt: $visit->getCreatedAt(),
            calendarSynced: null !== $visit->getCalendarCentralEventId(),
            updatedAt: $visit->getUpdatedAt(),
            updatedByName: $visit->getUpdatedByName(),
            updatedByAvatar: $visit->getUpdatedByAvatar(),
            clientDecision: $visit->getClientDecision(),
            clientDecisionAt: $visit->getClientDecisionAt(),
            applicationOutcome: $visit->getApplicationOutcome(),
            decisionDeadline: $visit->getDecisionDeadline(),
            refusalOrigin: $visit->getRefusalOrigin(),
            reportHighlights: $visit->getReportHighlights(),
            rentExcludingCharges: $visit->getRentExcludingCharges(),
            rentChargesIncluded: $visit->getRentChargesIncluded(),
        );
    }
}
