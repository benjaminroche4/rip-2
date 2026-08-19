<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\SearchHit;
use App\Auth\Entity\User;
use App\Auth\Service\UserSlugger;
use App\Contact\Entity\Contact;
use App\Dossier\Entity\Dossier;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lightweight cross-section lookups for the Cmd+K palette: a handful of
 * LIKE probes over indexed columns, each capped, each returning display
 * DTOs. Simple MySQL matching on purpose (no search engine on o2switch).
 */
final readonly class GlobalSearchRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserSlugger $userSlugger,
    ) {
    }

    /**
     * @return list<SearchHit>
     */
    public function contacts(string $q, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Contact::class, 'c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit);

        $conditions = "c.firstName LIKE :q OR c.lastName LIKE :q OR c.email LIKE :q OR c.reference LIKE :q OR CONCAT(c.firstName, ' ', c.lastName) LIKE :q";
        $qb->setParameter('q', self::like($q));
        // Phones live in E.164, admins type them freely: match on digits.
        $digits = ltrim(preg_replace('/\D+/', '', $q) ?? '', '0');
        if (\strlen($digits) >= 3) {
            $conditions .= ' OR c.phoneNumber LIKE :phone';
            $qb->setParameter('phone', '%'.$digits.'%');
        }
        $qb->where($conditions);

        $hits = [];
        /** @var Contact $contact */
        foreach ($qb->getQuery()->getResult() as $contact) {
            $name = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
            $hits[] = new SearchHit(
                title: '' !== $name ? $name : (string) $contact->getEmail(),
                subtitle: trim($contact->getReference().' · '.$contact->getEmail(), ' ·'),
                route: 'admin_contact_show',
                routeParams: ['reference' => (string) $contact->getReference()],
                badgeKey: $contact->getStatus()->labelKey(),
            );
        }

        return $hits;
    }

    /**
     * @return list<SearchHit>
     */
    public function dossiers(string $q, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Dossier::class, 'd')
            ->leftJoin('d.persons', 'p')
            ->where("d.name LIKE :q OR d.reference LIKE :q OR p.email LIKE :q OR CONCAT(p.firstName, ' ', p.lastName) LIKE :q")
            ->setParameter('q', self::like($q))
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit);

        $hits = [];
        /** @var Dossier $dossier */
        foreach ($qb->getQuery()->getResult() as $dossier) {
            $hits[] = new SearchHit(
                title: (string) $dossier->getName(),
                subtitle: (string) $dossier->getReference(),
                route: 'admin_dossier_show',
                routeParams: ['reference' => (string) $dossier->getReference()],
                badgeKey: $dossier->getEffectiveStatus()->labelKey(),
            );
        }

        return $hits;
    }

    /**
     * @return list<SearchHit>
     */
    public function users(string $q, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where("u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q OR CONCAT(u.firstName, ' ', u.lastName) LIKE :q")
            ->setParameter('q', self::like($q))
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit);

        $hits = [];
        /** @var User $user */
        foreach ($qb->getQuery()->getResult() as $user) {
            $firstName = (string) $user->getFirstName();
            $lastName = (string) $user->getLastName();
            $name = trim($firstName.' '.$lastName);
            $hits[] = new SearchHit(
                title: '' !== $name ? $name : (string) $user->getEmail(),
                subtitle: (string) $user->getEmail(),
                route: 'admin_user_show',
                routeParams: [
                    'uniqueId' => (string) $user->getUniqueId(),
                    'slug' => $this->userSlugger->slug($firstName, $lastName, (string) $user->getEmail()),
                ],
            );
        }

        return $hits;
    }

    /**
     * @return list<SearchHit>
     */
    public function visits(string $q, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('v')
            ->from(Visit::class, 'v')
            ->where('v.address LIKE :q OR v.reference LIKE :q')
            ->setParameter('q', self::like($q))
            ->orderBy('v.scheduledAt', 'DESC')
            ->setMaxResults($limit);

        $hits = [];
        /** @var Visit $visit */
        foreach ($qb->getQuery()->getResult() as $visit) {
            $hits[] = new SearchHit(
                title: (string) $visit->getAddress(),
                subtitle: trim(((string) $visit->getReference()).' · '.$visit->getScheduledAt()?->format('d.m.Y H\hi'), ' ·'),
                route: 'admin_visit_show',
                routeParams: ['reference' => (string) $visit->getReference()],
                badgeKey: $visit->getStatus()->labelKey(),
            );
        }

        return $hits;
    }

    /**
     * @return list<SearchHit>
     */
    public function agents(string $q, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(RealEstateAgent::class, 'a')
            ->leftJoin('a.agency', 'ag')
            ->where("a.firstName LIKE :q OR a.lastName LIKE :q OR a.email LIKE :q OR CONCAT(a.firstName, ' ', a.lastName) LIKE :q OR ag.name LIKE :q")
            ->setParameter('q', self::like($q))
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        $hits = [];
        /** @var RealEstateAgent $agent */
        foreach ($qb->getQuery()->getResult() as $agent) {
            $name = trim(((string) $agent->getFirstName()).' '.((string) $agent->getLastName()));
            $name = '' !== $name ? $name : (string) $agent->getEmail();
            $hits[] = new SearchHit(
                title: $name,
                // No detail page yet: the hit opens the list filtered on the name.
                subtitle: trim(((string) $agent->getAgency()?->getName()).' · '.$agent->getEmail(), ' ·'),
                route: 'admin_agents',
                routeParams: ['search' => $name],
            );
        }

        return $hits;
    }

    private static function like(string $q): string
    {
        return '%'.addcslashes(trim($q), '%_').'%';
    }
}
