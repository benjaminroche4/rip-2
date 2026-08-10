<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\UserActivityItem;
use App\Contact\Entity\Contact;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the business activity of a staff member (assigned leads, managed
 * dossiers) for the admin user profile. Returns light DTOs, newest first,
 * capped: the profile page is a summary, the full lists live in their own
 * sections.
 */
final readonly class UserActivityRepository
{
    private const LIMIT = 8;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<UserActivityItem>
     */
    public function assignedLeads(int $userId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('c.reference, c.firstName, c.lastName, c.createdAt')
            ->from(Contact::class, 'c')
            ->where('IDENTITY(c.assignedTo) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): UserActivityItem => new UserActivityItem(
            reference: (string) $row['reference'],
            label: trim(((string) $row['firstName']).' '.((string) $row['lastName'])),
            createdAt: \DateTimeImmutable::createFromInterface($row['createdAt']),
        ), $rows);
    }

    /**
     * @return list<UserActivityItem>
     */
    public function managedDossiers(int $userId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('d.reference, d.name, d.createdAt')
            ->from(Dossier::class, 'd')
            ->where('IDENTITY(d.manager) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): UserActivityItem => new UserActivityItem(
            reference: (string) $row['reference'],
            label: (string) $row['name'],
            createdAt: \DateTimeImmutable::createFromInterface($row['createdAt']),
        ), $rows);
    }
}
