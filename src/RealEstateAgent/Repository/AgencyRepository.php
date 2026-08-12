<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Domain\AgencySummary;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agency>
 */
class AgencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class);
    }

    /**
     * Reuses the agency typed in the agent modal, case-insensitively, or
     * stages a new one ("Foncia" and "foncia" must not become two agencies).
     * The caller flushes.
     */
    public function findOrCreate(string $name): Agency
    {
        $name = trim($name);

        $existing = $this->createQueryBuilder('a')
            ->where('LOWER(a.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($existing instanceof Agency) {
            return $existing;
        }

        $agency = (new Agency())
            ->setName($name)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->getEntityManager()->persist($agency);

        return $agency;
    }

    /**
     * Case-insensitive exact-name lookup, used by the standalone agency modal
     * to reject a duplicate with a clear message (vs. the agent modal's
     * silent find-or-create).
     */
    public function findByName(string $name): ?Agency
    {
        $agency = $this->createQueryBuilder('a')
            ->where('LOWER(a.name) = LOWER(:name)')
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $agency instanceof Agency ? $agency : null;
    }

    /**
     * Read model for the agencies view: every agency with how many directory
     * agents belong to it, accent-insensitive alphabetical order.
     *
     * @return list<AgencySummary>
     */
    public function findSummaries(): array
    {
        /** @var list<array{id: int, name: string, createdAt: \DateTimeImmutable, agentCount: int}> $rows */
        $rows = $this->createQueryBuilder('ag')
            ->select('ag.id AS id', 'ag.name AS name', 'ag.createdAt AS createdAt', 'COUNT(a.id) AS agentCount')
            ->leftJoin(RealEstateAgent::class, 'a', 'WITH', 'a.agency = ag')
            ->groupBy('ag.id')
            ->getQuery()
            ->getResult();

        $collator = new \Collator('fr_FR');
        usort($rows, static fn (array $a, array $b): int => $collator->compare($a['name'], $b['name']) ?: 0);

        return array_map(
            static fn (array $r): AgencySummary => new AgencySummary(
                id: (int) $r['id'],
                name: (string) $r['name'],
                agentCount: (int) $r['agentCount'],
                createdAt: $r['createdAt'] instanceof \DateTimeImmutable ? $r['createdAt'] : new \DateTimeImmutable(),
            ),
            $rows,
        );
    }

    /**
     * Names for the create-modal autocomplete datalist, alphabetical.
     *
     * @return list<string>
     */
    public function findAllNames(): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.name')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }
}
