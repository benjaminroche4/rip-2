<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Domain\AgencyDetail;
use App\RealEstateAgent\Domain\AgencySummary;
use App\RealEstateAgent\Domain\AgentSpecialty;
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
        return $this->findPagedSummaries('', null);
    }

    /**
     * Page de la vue agences : recherche, tri alphabétique et limite portés
     * par SQL (jamais toutes les agences en mémoire).
     *
     * @return list<AgencySummary>
     */
    public function findPagedSummaries(string $search, ?int $limit): array
    {
        $qb = $this->createQueryBuilder('ag')
            ->select('ag.id AS id', 'ag.name AS name', 'b.name AS brand', 'ag.createdAt AS createdAt', 'ag.logoFilename AS logoFilename', 'ag.website AS website', 'ag.specialties AS specialties', 'ag.note AS note', 'ag.deactivatedAt AS deactivatedAt', 'COUNT(a.id) AS agentCount')
            ->leftJoin('ag.brand', 'b')
            ->leftJoin(RealEstateAgent::class, 'a', 'WITH', 'a.agency = ag')
            ->groupBy('ag.id')
            ->addGroupBy('b.name')
            ->addGroupBy('ag.logoFilename')
            ->orderBy('ag.name', 'ASC')
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        /** @var list<array{id: int, name: string, brand: string|null, createdAt: \DateTimeImmutable, logoFilename: string|null, website: string|null, specialties: list<string>|null, note: string|null, deactivatedAt: \DateTimeImmutable|null, agentCount: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): AgencySummary => new AgencySummary(
                id: (int) $r['id'],
                name: (string) $r['name'],
                brand: null !== $r['brand'] ? (string) $r['brand'] : null,
                agentCount: (int) $r['agentCount'],
                createdAt: $r['createdAt'],
                logoFilename: null !== $r['logoFilename'] ? (string) $r['logoFilename'] : null,
                website: null !== $r['website'] ? (string) $r['website'] : null,
                specialties: array_map(AgentSpecialty::from(...), $r['specialties'] ?? []),
                note: null !== $r['note'] ? (string) $r['note'] : null,
                active: null === $r['deactivatedAt'],
            ),
            $rows,
        );
    }

    /**
     * Names for the create-modal autocomplete datalist, alphabetical.
     * Deactivated agencies stay out: they must not be suggested anymore
     * (findOrCreate still matches them if the exact name is typed).
     *
     * @return list<string>
     */
    public function findAllNames(): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.name')
            ->where('a.deactivatedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }

    /**
     * Read model for the agency detail page.
     */
    public function findDetail(int $id): ?AgencyDetail
    {
        $agency = $this->createQueryBuilder('ag')
            ->leftJoin('ag.brand', 'b')
            ->addSelect('b')
            ->where('ag.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$agency instanceof Agency) {
            return null;
        }

        return new AgencyDetail(
            id: (int) $agency->getId(),
            name: (string) $agency->getName(),
            brand: $agency->getBrand()?->getName(),
            address: $agency->getAddress(),
            phone: $agency->getPhone(),
            email: $agency->getEmail(),
            createdAt: $agency->getCreatedAt() ?? new \DateTimeImmutable(),
            logoFilename: $agency->getLogoFilename(),
            website: $agency->getWebsite(),
            specialties: $agency->getSpecialties(),
            note: $agency->getNote(),
            active: $agency->isActive(),
            areas: $agency->getAreas(),
            latitude: $agency->getLatitude(),
            longitude: $agency->getLongitude(),
        );
    }

    /** Nombre d'agences après recherche ('' = toutes). */
    public function countFiltered(string $search = ''): int
    {
        $qb = $this->createQueryBuilder('ag')
            ->select('COUNT(DISTINCT ag.id)')
            ->leftJoin('ag.brand', 'b');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applySearch(\Doctrine\ORM\QueryBuilder $qb, string $search): void
    {
        $needle = trim($search);
        if ('' === $needle) {
            return;
        }
        $qb->andWhere('ag.name LIKE :needle OR b.name LIKE :needle')
            ->setParameter('needle', '%'.addcslashes($needle, '%_\\').'%');
    }
}
