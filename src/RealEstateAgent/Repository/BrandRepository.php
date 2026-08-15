<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Repository;

use App\RealEstateAgent\Entity\Brand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
class BrandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Brand::class);
    }

    /**
     * Reuses the brand typed in the agency form, case-insensitively, or
     * stages a new one ("Foncia" and "foncia" must not become two brands).
     * The caller flushes.
     */
    public function findOrCreate(string $name): Brand
    {
        $name = trim($name);

        $existing = $this->createQueryBuilder('b')
            ->where('LOWER(b.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($existing instanceof Brand) {
            return $existing;
        }

        $brand = new Brand($name);
        $this->getEntityManager()->persist($brand);

        return $brand;
    }

    /**
     * Names for the create-form autocomplete datalist, alphabetical.
     *
     * @return list<string>
     */
    public function findAllNames(): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.name')
            ->orderBy('b.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }
}
