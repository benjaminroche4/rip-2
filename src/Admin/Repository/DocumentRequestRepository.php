<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\DocumentRequestSummary;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentRequest>
 */
class DocumentRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentRequest::class);
    }

    /**
     * Paginated read model for the recent-requests table on the documents hub.
     * Returns summaries (DTOs) ordered by creation date desc. Persons are
     * eager-loaded via a single join so the table renders without lazy-load
     * queries per row.
     *
     * @return list<DocumentRequestSummary>
     */
    public function findSummariesPage(int $limit, int $offset = 0): array
    {
        /** @var list<DocumentRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->leftJoin('r.persons', 'p')
            ->addSelect('p')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('p.position', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (DocumentRequest $r): DocumentRequestSummary => new DocumentRequestSummary(
                id: (int) $r->getId(),
                createdAt: $r->getCreatedAt() ?? new \DateTimeImmutable(),
                typology: $r->getTypology() ?? throw new \LogicException('DocumentRequest missing typology'),
                language: $r->getLanguage(),
                personNames: array_values(array_map(
                    static fn (PersonRequest $p): string => trim(($p->getFirstName() ?? '').' '.($p->getLastName() ?? '')),
                    $r->getPersons()->toArray(),
                )),
            ),
            $requests,
        );
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
