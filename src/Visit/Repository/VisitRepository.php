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
     * Visits actually carried out on a dossier: the dossier step path is
     * gated on this, a booked-but-not-done visit does not count.
     */
    public function countDoneByDossier(int $dossierId): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('IDENTITY(v.dossier) = :dossier')
            ->andWhere('v.status = :done')
            ->setParameter('dossier', $dossierId)
            ->setParameter('done', VisitStatus::Done)
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
