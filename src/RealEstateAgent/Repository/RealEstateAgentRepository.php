<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Domain\AgentSummary;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RealEstateAgent>
 */
class RealEstateAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RealEstateAgent::class);
    }

    /**
     * Read model for the admin list — a directory, so alphabetical order.
     *
     * @return list<AgentSummary>
     */
    public function findSummaries(): array
    {
        /** @var list<RealEstateAgent> $agents */
        $agents = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        // Case- and accent-insensitive alphabetical order, independent of
        // the database collation.
        $collator = new \Collator('fr_FR');
        usort($agents, static function (RealEstateAgent $a, RealEstateAgent $b) use ($collator): int {
            return $collator->compare(
                $a->getLastName().' '.$a->getFirstName(),
                $b->getLastName().' '.$b->getFirstName(),
            ) ?: 0;
        });

        $summaries = [];
        foreach ($agents as $agent) {
            $summaries[] = new AgentSummary(
                id: (int) $agent->getId(),
                firstName: (string) $agent->getFirstName(),
                lastName: (string) $agent->getLastName(),
                agency: $agent->getAgency()?->getName(),
                email: $agent->getEmail(),
                phone: $agent->getPhone(),
                specialties: $agent->getSpecialties(),
                position: $agent->getPosition(),
                createdAt: $agent->getCreatedAt() ?? new \DateTimeImmutable(),
            );
        }

        return $summaries;
    }
}
