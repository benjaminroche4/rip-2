<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Dossier\Domain\DossierSummary;
use App\Dossier\Entity\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    /**
     * Read model for the admin list, newest first.
     *
     * @return list<DossierSummary>
     */
    public function findSummaries(): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->leftJoin('d.persons', 'p')
            ->addSelect('p')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $summaries = [];
        foreach ($dossiers as $dossier) {
            $primaryName = null;
            foreach ($dossier->getPersons() as $person) {
                if ($person->isPrimaryContact()) {
                    $primaryName = trim($person->getFirstName().' '.$person->getLastName());
                    break;
                }
            }

            $summaries[] = new DossierSummary(
                id: (int) $dossier->getId(),
                name: (string) $dossier->getName(),
                primaryTenantName: $primaryName,
                personCount: $dossier->getPersons()->count(),
                createdAt: $dossier->getCreatedAt() ?? new \DateTimeImmutable(),
            );
        }

        return $summaries;
    }
}
