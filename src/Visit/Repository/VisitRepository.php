<?php

declare(strict_types=1);

namespace App\Visit\Repository;

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

    /**
     * Read model for the visits page: every visit from the start of the given
     * day (Europe/Paris) onwards, chronological.
     *
     * @return list<VisitSummary>
     */
    public function findOneSummary(int $id): ?VisitSummary
    {
        $visit = $this->find($id);

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
    public function findArchivedSummaries(\DateTimeImmutable $now, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')->addSelect('d')
            ->leftJoin('v.agent', 'a')->addSelect('a')
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('v.scheduledAt < :start')
            ->setParameter('start', $now->setTime(0, 0))
            ->orderBy('v.scheduledAt', 'DESC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Visit> $visits */
        $visits = $qb->getQuery()->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    public function countUpcoming(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.scheduledAt >= :start')
            ->setParameter('start', $now->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countArchived(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.scheduledAt < :start')
            ->setParameter('start', $now->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();
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
            ->leftJoin('v.assignee', 'u')->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')->addSelect('b')
            ->where('d.id = :dossier')
            ->setParameter('dossier', $dossierId)
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    public function findUpcomingSummaries(\DateTimeImmutable $from): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')
            ->addSelect('d')
            ->leftJoin('v.agent', 'a')
            ->addSelect('a')
            ->leftJoin('v.assignee', 'u')
            ->addSelect('u')
            ->leftJoin('v.bookedBy', 'b')
            ->addSelect('b')
            ->where('v.scheduledAt >= :from')
            ->setParameter('from', $from->setTime(0, 0))
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->toSummary(...), $visits);
    }

    private function toSummary(Visit $visit): VisitSummary
    {
        $agent = $visit->getAgent();
        $agentName = null;
        if (null !== $agent) {
            $agentName = trim($agent->getFirstName().' '.$agent->getLastName());
        }

        return new VisitSummary(
            id: (int) $visit->getId(),
            reference: (string) $visit->getReference(),
            scheduledAt: $visit->getScheduledAt() ?? new \DateTimeImmutable(),
            address: (string) $visit->getAddress(),
            latitude: $visit->getLatitude(),
            longitude: $visit->getLongitude(),
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
            clientFeeling: $visit->getClientFeeling(),
        );
    }
}
