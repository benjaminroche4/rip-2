<?php

declare(strict_types=1);

namespace App\Contact\Repository;

use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactEventItem;
use App\Contact\Domain\ContactStatus;
use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactEvent>
 */
class ContactEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactEvent::class);
    }

    /**
     * History for a submission, newest first.
     *
     * @return list<ContactEventItem>
     */
    public function listForContact(int $contactId): array
    {
        /** @var list<ContactEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->andWhere('e.contact = :contactId')
            ->setParameter('contactId', $contactId)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (ContactEvent $event): ContactEventItem => new ContactEventItem(
                id: (int) $event->getId(),
                status: $event->getStatus(),
                closureReason: $event->getClosureReason(),
                authorName: $event->getAuthorName(),
                authorAvatar: $event->getAuthorAvatar(),
                createdAt: $event->getCreatedAt(),
            ),
            $events,
        );
    }

    /** Persisted with the caller's flush (no flush here). */
    public function record(Contact $contact, ?ContactStatus $status, ?ClosureReason $closureReason, ?string $authorName, ?string $authorAvatar): void
    {
        $event = (new ContactEvent())
            ->setContact($contact)
            ->setStatus($status)
            ->setClosureReason($closureReason)
            ->setAuthorName($authorName)
            ->setAuthorAvatar($authorAvatar);

        $this->getEntityManager()->persist($event);
    }
}
