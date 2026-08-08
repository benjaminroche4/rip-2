<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Dossier\Entity\DossierEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierEvent>
 */
class DossierEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierEvent::class);
    }

    /**
     * @return list<DossierEvent>
     */
    public function listForDossier(int $dossierId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :dossier')
            ->setParameter('dossier', $dossierId)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
