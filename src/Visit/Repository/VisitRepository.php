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
    public function findUpcomingSummaries(\DateTimeImmutable $from): array
    {
        /** @var list<Visit> $visits */
        $visits = $this->createQueryBuilder('v')
            ->leftJoin('v.dossier', 'd')
            ->addSelect('d')
            ->leftJoin('v.agent', 'a')
            ->addSelect('a')
            ->where('v.scheduledAt >= :from')
            ->setParameter('from', $from->setTime(0, 0))
            ->orderBy('v.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        $summaries = [];
        foreach ($visits as $visit) {
            $agent = $visit->getAgent();
            $agentName = null;
            if (null !== $agent) {
                $agentName = trim($agent->getFirstName().' '.$agent->getLastName());
            }

            $summaries[] = new VisitSummary(
                id: (int) $visit->getId(),
                scheduledAt: $visit->getScheduledAt() ?? new \DateTimeImmutable(),
                address: (string) $visit->getAddress(),
                latitude: $visit->getLatitude(),
                longitude: $visit->getLongitude(),
                dossierName: (string) $visit->getDossier()?->getName(),
                dossierReference: (string) $visit->getDossier()?->getReference(),
                agentName: $agentName,
            );
        }

        return $summaries;
    }
}
