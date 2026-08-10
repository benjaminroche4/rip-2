<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Entity\Agency;
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
