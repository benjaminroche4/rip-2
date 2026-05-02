<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\UserListItem;
use App\Admin\Domain\UserProfile;
use App\Auth\Domain\Language;
use App\Auth\Entity\User;
use App\Auth\Service\UserSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Reads users for the admin space and returns DTOs so the template never
 * touches the Doctrine entity. Lives in the Admin context: Auth's own
 * UserRepository keeps returning entities for security flows (login,
 * password upgrade), this one is the admin-side projection.
 */
final readonly class AdminUserRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserSlugger $userSlugger,
    ) {
    }

    /**
     * @return list<UserListItem>
     */
    public function listAll(): array
    {
        return $this->fetch(null);
    }

    /**
     * Returns the first $limit users (newest first). Used for the admin
     * "load more" pagination — caller passes page * perPage.
     *
     * @return list<UserListItem>
     */
    public function listFirst(int $limit): array
    {
        return $this->fetch(max(1, $limit));
    }

    public function count(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByUniqueId(string $uniqueId): ?UserProfile
    {
        if (!Ulid::isValid($uniqueId)) {
            return null;
        }

        $rows = $this->em->createQueryBuilder()
            ->select('u.id, u.uniqueId, u.email, u.firstName, u.lastName, u.roles, u.avatarFilename, u.createdAt, u.lastLoginAt, u.googleId, u.password, u.language, u.isProfileComplete')
            ->from(User::class, 'u')
            ->where('u.uniqueId = :uniqueId')
            ->setParameter('uniqueId', Ulid::fromString($uniqueId), 'ulid')
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return null;
        }
        $row = $rows[0];

        $firstName = (string) $row['firstName'];
        $lastName = (string) $row['lastName'];
        $email = (string) $row['email'];

        $language = $row['language'] ?? null;
        if (\is_string($language)) {
            $language = Language::tryFrom($language);
        }

        return new UserProfile(
            id: (int) $row['id'],
            uniqueId: $this->coerceUlid($row['uniqueId']),
            slug: $this->userSlugger->slug($firstName, $lastName, $email),
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            roles: array_values(array_map('strval', (array) $row['roles'])),
            avatarFilename: $row['avatarFilename'] ?? null,
            createdAt: $this->coerceDateTime($row['createdAt']),
            lastLoginAt: isset($row['lastLoginAt']) ? $this->coerceDateTime($row['lastLoginAt']) : null,
            hasGoogleAuth: !empty($row['googleId']),
            hasPasswordAuth: !empty($row['password']),
            language: $language instanceof Language ? $language : null,
            isProfileComplete: (bool) ($row['isProfileComplete'] ?? false),
        );
    }

    /**
     * @return list<UserListItem>
     */
    private function fetch(?int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id, u.uniqueId, u.email, u.firstName, u.lastName, u.roles, u.avatarFilename, u.createdAt, u.lastLoginAt')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $items = [];
        foreach ($rows as $row) {
            $firstName = (string) $row['firstName'];
            $lastName = (string) $row['lastName'];
            $email = (string) $row['email'];

            $items[] = new UserListItem(
                id: (int) $row['id'],
                uniqueId: $this->coerceUlid($row['uniqueId']),
                slug: $this->userSlugger->slug($firstName, $lastName, $email),
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                roles: array_values(array_map('strval', (array) $row['roles'])),
                avatarFilename: $row['avatarFilename'] ?? null,
                createdAt: $this->coerceDateTime($row['createdAt']),
                lastLoginAt: isset($row['lastLoginAt']) ? $this->coerceDateTime($row['lastLoginAt']) : null,
            );
        }

        return $items;
    }

    private function coerceUlid(mixed $value): Ulid
    {
        if ($value instanceof Ulid) {
            return $value;
        }

        return Ulid::fromString((string) $value);
    }

    private function coerceDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        return \DateTimeImmutable::createFromInterface($value);
    }
}
