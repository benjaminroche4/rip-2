<?php

namespace App\Auth\Repository;

use App\Auth\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    /**
     * Team members a contact submission can be assigned to: admins plus
     * anyone granted the Leads section.
     *
     * @return list<User>
     */
    /**
     * Staff members who can perform a property visit: any back-office
     * account (staff or admin) carrying the "visit agent" function.
     *
     * @return list<User>
     */
    public function findVisitAgents(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :admin OR u.roles LIKE :staff')
            ->andWhere('u.staffFunctions LIKE :fn')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('staff', '%ROLE_STAFF%')
            ->setParameter('fn', '%visit_agent%')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function findAdmins(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :admin OR u.roles LIKE :contacts')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('contacts', '%ROLE_SECTION_CONTACTS%')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * Staff assignable on dossiers: admins plus anyone granted the
     * Dossiers section.
     *
     * @return list<User>
     */
    public function findStaff(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :admin OR u.roles LIKE :dossiers')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('dossiers', '%ROLE_SECTION_DOSSIERS%')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
