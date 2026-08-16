<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Domain\AgentDetail;
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
     * Page du répertoire : recherche et tri portés par SQL, jamais tout
     * l'annuaire en mémoire (la liste peut devenir très longue).
     *
     * @return list<AgentSummary>
     */
    public function findPagedSummaries(string $search, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        /** @var list<RealEstateAgent> $agents */
        $agents = $qb->getQuery()->getResult();

        return array_map($this->toSummary(...), $agents);
    }

    /** Nombre de lignes après recherche ('' = tout l'annuaire). */
    public function countFiltered(string $search = ''): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.agency', 'ag');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applySearch(\Doctrine\ORM\QueryBuilder $qb, string $search): void
    {
        $needle = trim($search);
        if ('' === $needle) {
            return;
        }
        $like = '%'.addcslashes($needle, '%_\\').'%';
        $qb->andWhere("CONCAT(a.firstName, ' ', a.lastName) LIKE :needle OR CONCAT(a.lastName, ' ', a.firstName) LIKE :needle OR ag.name LIKE :needle OR a.email LIKE :needle OR a.phone LIKE :needle")
            ->setParameter('needle', $like);
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
            ->getQuery()
            ->getResult();

        return $this->mapSummaries($agents);
    }

    /**
     * The agency's directory: its agents, same alphabetical order as the
     * main list.
     *
     * @return list<AgentSummary>
     */
    public function findSummariesByAgency(int $agencyId): array
    {
        /** @var list<RealEstateAgent> $agents */
        $agents = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->where('ag.id = :agencyId')
            ->setParameter('agencyId', $agencyId)
            ->getQuery()
            ->getResult();

        return $this->mapSummaries($agents);
    }

    /**
     * Read model for the agent detail page.
     */
    public function findDetail(int $id): ?AgentDetail
    {
        $agent = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$agent instanceof RealEstateAgent) {
            return null;
        }

        return new AgentDetail(
            id: (int) $agent->getId(),
            firstName: (string) $agent->getFirstName(),
            lastName: (string) $agent->getLastName(),
            agencyId: $agent->getAgency()?->getId(),
            agencyName: $agent->getAgency()?->getName(),
            email: $agent->getEmail(),
            phone: $agent->getPhone(),
            specialties: $agent->getSpecialties(),
            position: $agent->getPosition(),
            createdAt: $agent->getCreatedAt() ?? new \DateTimeImmutable(),
            avatarFilename: $agent->getAvatarFilename(),
            note: $agent->getNote(),
            active: $agent->isActive(),
            createdByName: $agent->getCreatedByName(),
            updatedAt: $agent->getUpdatedAt(),
            updatedByName: $agent->getUpdatedByName(),
            professionalCards: $agent->getProfessionalCards(),
        );
    }

    /**
     * Active agents only, alphabetical: the agent pickers (visit form and
     * visit detail dropdown) must not offer a deactivated agent, while the
     * directory keeps showing everyone.
     *
     * @return list<RealEstateAgent>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.deactivatedAt IS NULL')
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Case- and accent-insensitive alphabetical order, independent of the
     * database collation, then the entity → DTO mapping.
     *
     * @param list<RealEstateAgent> $agents
     *
     * @return list<AgentSummary>
     */
    private function mapSummaries(array $agents): array
    {
        $collator = new \Collator('fr_FR');
        usort($agents, static function (RealEstateAgent $a, RealEstateAgent $b) use ($collator): int {
            return $collator->compare(
                $a->getLastName().' '.$a->getFirstName(),
                $b->getLastName().' '.$b->getFirstName(),
            ) ?: 0;
        });

        return array_map($this->toSummary(...), $agents);
    }

    private function toSummary(RealEstateAgent $agent): AgentSummary
    {
        return new AgentSummary(
            id: (int) $agent->getId(),
            firstName: (string) $agent->getFirstName(),
            lastName: (string) $agent->getLastName(),
            agency: $agent->getAgency()?->getName(),
            agencyId: $agent->getAgency()?->getId(),
            email: $agent->getEmail(),
            phone: $agent->getPhone(),
            specialties: $agent->getSpecialties(),
            position: $agent->getPosition(),
            createdAt: $agent->getCreatedAt() ?? new \DateTimeImmutable(),
            avatarFilename: $agent->getAvatarFilename(),
            note: $agent->getNote(),
            active: $agent->isActive(),
            updatedAt: $agent->getUpdatedAt(),
        );
    }
}
