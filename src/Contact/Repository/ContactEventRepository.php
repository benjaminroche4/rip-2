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
                kind: $event->getKind(),
                detail: $event->getDetail(),
                authorName: $event->getAuthorName(),
                authorAvatar: $event->getAuthorAvatar(),
                createdAt: $event->getCreatedAt(),
            ),
            $events,
        );
    }

    /**
     * Who last moved the submission to this status, read from the follow-up
     * thread (each entry snapshots its author). Feeds the terminal banner:
     * a lead can be closed, reopened and closed again by someone else.
     *
     * @return array{name: string, avatar: ?string}|null
     */
    public function findStatusAuthor(int $contactId, ContactStatus $status): ?array
    {
        /** @var ContactEvent|null $event */
        $event = $this->createQueryBuilder('e')
            ->andWhere('e.contact = :contactId')
            ->andWhere('e.status = :status')
            ->setParameter('contactId', $contactId)
            ->setParameter('status', $status)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $name = $event?->getAuthorName();

        return null !== $name && '' !== $name
            ? ['name' => $name, 'avatar' => $event->getAuthorAvatar()]
            : null;
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

    /**
     * Business event on the follow-up thread (next step confirmed, visio
     * planned/moved/cancelled...); flushes immediately.
     */
    public function recordKind(Contact $contact, string $kind, ?string $detail = null, ?string $authorName = null, ?string $authorAvatar = null): void
    {
        $event = (new ContactEvent())
            ->setContact($contact)
            ->setKind($kind)
            ->setDetail($detail)
            ->setAuthorName($authorName)
            ->setAuthorAvatar($authorAvatar);

        $em = $this->getEntityManager();
        $em->persist($event);
        $em->flush();
    }

    /** Recap email sent to the client; flushes immediately. */
    public function recordRecapSent(Contact $contact, bool $withPayment, ?string $authorName, ?string $authorAvatar): void
    {
        $event = (new ContactEvent())
            ->setContact($contact)
            ->setKind($withPayment ? 'recap_email_payment' : 'recap_email')
            ->setAuthorName($authorName)
            ->setAuthorAvatar($authorAvatar);

        $em = $this->getEntityManager();
        $em->persist($event);
        $em->flush();
    }
}
