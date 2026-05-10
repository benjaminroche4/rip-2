<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    public function findOneBySlug(string $slug): ?Document
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
