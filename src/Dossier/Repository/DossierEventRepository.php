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

    /**
     * Most recent event of a kind: the closure banner reads who archived the
     * dossier from the audit trail, no extra column to keep in sync (a
     * dossier can be closed, reopened and closed again by someone else).
     */
    public function findLatestOfKind(int $dossierId, string $kind): ?DossierEvent
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :dossier')
            ->andWhere('e.kind = :kind')
            ->setParameter('dossier', $dossierId)
            ->setParameter('kind', $kind)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
