<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Domain\AgencyDetail;
use App\RealEstateAgent\Domain\AgencyPickerOption;
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
        $this->ensureUniqueReference($agency);
        $this->getEntityManager()->persist($agency);

        return $agency;
    }

    /**
     * Référence aléatoire : re-tire tant qu'elle existe déjà (le paradoxe des
     * anniversaires rend la collision plausible bien avant le million de
     * fiches; l'index unique reste le filet pour la course résiduelle).
     */
    public function ensureUniqueReference(Agency $agency): void
    {
        for ($i = 0; $i < 5 && $this->count(['reference' => $agency->getReference()]) > 0; ++$i) {
            $agency->regenerateReference();
        }
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
    public function findPagedSummaries(string $search, ?int $limit, bool $favoritesOnly = false): array
    {
        $qb = $this->createQueryBuilder('ag')
            ->select('ag.id AS id', 'ag.name AS name', 'b.name AS brand', 'ag.createdAt AS createdAt', 'ag.logoFilename AS logoFilename', 'ag.website AS website', 'ag.specialties AS specialties', 'ag.note AS note', 'ag.deactivatedAt AS deactivatedAt', 'ag.areas AS areas', 'ag.reference AS reference', 'ag.favoritedAt AS favoritedAt', 'COUNT(a.id) AS agentCount')
            ->leftJoin('ag.brand', 'b')
            ->leftJoin(RealEstateAgent::class, 'a', 'WITH', 'a.agency = ag')
            ->groupBy('ag.id')
            ->addGroupBy('b.name')
            ->addGroupBy('ag.logoFilename')
            ->orderBy('ag.name', 'ASC')
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        if ($favoritesOnly) {
            $qb->andWhere('ag.favoritedAt IS NOT NULL');
        }
        /** @var list<array{id: int, name: string, brand: string|null, createdAt: \DateTimeImmutable, logoFilename: string|null, website: string|null, specialties: list<string>|null, note: string|null, deactivatedAt: \DateTimeImmutable|null, areas: string|null, reference: string|null, favoritedAt: \DateTimeImmutable|null, agentCount: int}> $rows */
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
                areaLabels: array_map(
                    static fn (string $code): string => \App\Contact\Domain\ParisDistricts::LABELS[$code] ?? $code,
                    array_values(array_filter(array_map(trim(...), explode(',', (string) ($r['areas'] ?? ''))))),
                ),
                allAreas: \App\Contact\Domain\ParisDistricts::allArrondissementsSelected((string) ($r['areas'] ?? '')),
                note: null !== $r['note'] ? (string) $r['note'] : null,
                active: null === $r['deactivatedAt'],
                reference: (string) ($r['reference'] ?? ''),
                favorite: null !== $r['favoritedAt'],
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
     * Options for the agency picker on the "new agent" form: active agencies
     * only, alphabetical, one array query (no full entity hydration).
     *
     * @return list<AgencyPickerOption>
     */
    public function findPickerOptions(): array
    {
        /** @var list<array{id: int, name: string, logoFilename: string|null, address: string|null, brand: string|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.id', 'a.name', 'a.logoFilename', 'a.address', 'b.name AS brand')
            ->leftJoin('a.brand', 'b')
            ->where('a.deactivatedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $r): AgencyPickerOption => new AgencyPickerOption(
                id: (int) $r['id'],
                name: (string) $r['name'],
                logoFilename: null !== $r['logoFilename'] ? (string) $r['logoFilename'] : null,
                address: null !== $r['address'] ? (string) $r['address'] : null,
                brand: null !== $r['brand'] ? (string) $r['brand'] : null,
            ),
            $rows,
        );
    }

    /** Fiche par référence publique (AY-xxxxxx), utilisée par l'URL admin. */
    public function findDetailByReference(string $reference): ?AgencyDetail
    {
        return $this->findDetailMatching('ag.reference = :value', $reference);
    }

    /**
     * Read model for the agency detail page.
     */
    public function findDetail(int $id): ?AgencyDetail
    {
        return $this->findDetailMatching('ag.id = :value', $id);
    }

    private function findDetailMatching(string $where, int|string $value): ?AgencyDetail
    {
        $agency = $this->createQueryBuilder('ag')
            ->leftJoin('ag.brand', 'b')
            ->addSelect('b')
            ->where($where)
            ->setParameter('value', $value)
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
            reference: $agency->getReference(),
            createdByName: $agency->getCreatedByName(),
            createdByAvatar: $agency->getCreatedByAvatar(),
            updatedAt: $agency->getUpdatedAt(),
            updatedByName: $agency->getUpdatedByName(),
            updatedByAvatar: $agency->getUpdatedByAvatar(),
            favorite: $agency->isFavorite(),
        );
    }

    /** Nombre d'agences après recherche ('' = toutes), favoris seuls en option. */
    public function countFiltered(string $search = '', bool $favoritesOnly = false): int
    {
        $qb = $this->createQueryBuilder('ag')
            ->select('COUNT(DISTINCT ag.id)')
            ->leftJoin('ag.brand', 'b');
        $this->applySearch($qb, $search);
        if ($favoritesOnly) {
            $qb->andWhere('ag.favoritedAt IS NOT NULL');
        }

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
