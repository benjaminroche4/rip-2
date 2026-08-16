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
    public function findPagedSummaries(string $search, int $limit, bool $favoritesOnly = false, ?string $specialty = null, ?string $area = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->setMaxResults($limit);
        if ($favoritesOnly) {
            $qb->andWhere('a.favoritedAt IS NOT NULL');
        }
        $this->applySearch($qb, $search);
        $this->applyDirectoryFilters($qb, $specialty, $area);

        /** @var list<RealEstateAgent> $agents */
        $agents = $qb->getQuery()->getResult();

        return array_map($this->toSummary(...), $agents);
    }

    /** Nombre de lignes après recherche et filtres ('' / null = tout l'annuaire). */
    public function countFiltered(string $search = '', bool $favoritesOnly = false, ?string $specialty = null, ?string $area = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.agency', 'ag');
        if ($favoritesOnly) {
            $qb->andWhere('a.favoritedAt IS NOT NULL');
        }
        $this->applySearch($qb, $search);
        $this->applyDirectoryFilters($qb, $specialty, $area);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Filtres discrets de l'annuaire, whitelistés en amont (valeur d'enum
     * AgentSpecialty / code ParisDistricts).
     *
     * - Spécialité : la colonne JSON stocke les backing values, le guillemet
     *   dans le motif évite tout faux positif de sous-chaîne.
     * - Quartier : l'agent matche par son propre CSV ou celui de son agence
     *   (les agents en agence héritent du secteur de l'agence). Les codes ne
     *   sont pas préfixe-safe ('1e' est un préfixe de '11e'), donc le CSV est
     *   borné de virgules avant le LIKE.
     */
    private function applyDirectoryFilters(\Doctrine\ORM\QueryBuilder $qb, ?string $specialty, ?string $area): void
    {
        if (null !== $specialty && '' !== $specialty) {
            $qb->andWhere('a.specialties LIKE :specialty')
                ->setParameter('specialty', '%"'.$specialty.'"%');
        }
        if (null !== $area && '' !== $area) {
            $qb->andWhere("CONCAT(',', COALESCE(a.areas, ''), ',') LIKE :area OR CONCAT(',', COALESCE(ag.areas, ''), ',') LIKE :area")
                ->setParameter('area', '%,'.$area.',%');
        }
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

    /** Fiche par référence publique (AG-xxxxxx), utilisée par l'URL admin. */
    public function findDetailByReference(string $reference): ?AgentDetail
    {
        return $this->findDetailMatching('a.reference = :value', $reference);
    }

    /**
     * Read model for the agent detail page.
     */
    public function findDetail(int $id): ?AgentDetail
    {
        return $this->findDetailMatching('a.id = :value', $id);
    }

    /**
     * Référence aléatoire : re-tire tant qu'elle existe déjà (le paradoxe des
     * anniversaires rend la collision plausible bien avant le million de
     * fiches; l'index unique reste le filet pour la course résiduelle).
     */
    public function ensureUniqueReference(RealEstateAgent $agent): void
    {
        for ($i = 0; $i < 5 && $this->count(['reference' => $agent->getReference()]) > 0; ++$i) {
            $agent->regenerateReference();
        }
    }

    /** Agents rattachés à une agence, sans hydrater les lignes. */
    public function countByAgency(int $agencyId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.agency = :agencyId')
            ->setParameter('agencyId', $agencyId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findDetailMatching(string $where, int|string $value): ?AgentDetail
    {
        $agent = $this->createQueryBuilder('a')
            ->leftJoin('a.agency', 'ag')
            ->addSelect('ag')
            ->where($where)
            ->setParameter('value', $value)
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
            createdByAvatar: $agent->getCreatedByAvatar(),
            reference: $agent->getReference(),
            updatedByAvatar: $agent->getUpdatedByAvatar(),
            address: $agent->getAddress(),
            areas: $agent->getAreas(),
            latitude: $agent->getLatitude(),
            longitude: $agent->getLongitude(),
            favorite: $agent->isFavorite(),
            agencyReference: $agent->getAgency()?->getReference(),
            introEmailSentAt: $agent->getIntroEmailSentAt(),
        );
    }

    /**
     * Doublon probable à la création : même email (insensible à la casse)
     * ou même téléphone (E.164). Première fiche correspondante, fiches
     * désactivées comprises (un doublon dormant reste un doublon).
     */
    public function findPotentialDuplicate(?string $email, ?string $phone): ?RealEstateAgent
    {
        $email = trim((string) $email);
        $phone = trim((string) $phone);
        if ('' === $email && '' === $phone) {
            return null;
        }

        $qb = $this->createQueryBuilder('a')->setMaxResults(1);
        $conditions = [];
        if ('' !== $email) {
            $conditions[] = 'LOWER(a.email) = LOWER(:email)';
            $qb->setParameter('email', $email);
        }
        if ('' !== $phone) {
            $conditions[] = 'a.phone = :phone';
            $qb->setParameter('phone', $phone);
        }
        $qb->where(implode(' OR ', $conditions));

        $match = $qb->getQuery()->getResult()[0] ?? null;

        return $match instanceof RealEstateAgent ? $match : null;
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
            favorite: $agent->isFavorite(),
            agencyLogo: $agent->getAgency()?->getLogoFilename(),
            reference: $agent->getReference(),
            agencyReference: $agent->getAgency()?->getReference(),
        );
    }
}
