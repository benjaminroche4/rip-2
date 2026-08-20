<?php

declare(strict_types=1);

namespace App\Contact\Repository;

use App\Contact\Domain\ContactNoteItem;
use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactNote>
 */
class ContactNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactNote::class);
    }

    /**
     * Thread for a submission, newest first.
     *
     * @return list<ContactNoteItem>
     */
    public function listForContact(int $contactId): array
    {
        /** @var list<ContactNote> $notes */
        $notes = $this->createQueryBuilder('n')
            ->andWhere('n.contact = :contactId')
            ->setParameter('contactId', $contactId)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map($this->toItem(...), $notes);
    }

    public function add(Contact $contact, string $text, int $authorId, string $authorName, ?string $authorAvatar, ?ContactNote $parent = null): ContactNote
    {
        // Depth is capped at one: answering a reply attaches to its root.
        if (null !== $parent?->getParentNote()) {
            $parent = $parent->getParentNote();
        }

        $note = (new ContactNote())
            ->setContact($contact)
            ->setText($text)
            ->setAuthorId($authorId)
            ->setAuthorName($authorName)
            ->setAuthorAvatar($authorAvatar)
            ->setParentNote($parent);

        $em = $this->getEntityManager();
        $em->persist($note);
        $em->flush();

        return $note;
    }

    public function updateText(ContactNote $note, string $text): void
    {
        $note->setText($text);
        $this->getEntityManager()->flush();
    }

    public function remove(ContactNote $note): void
    {
        $em = $this->getEntityManager();
        $em->remove($note);
        $em->flush();
    }

    private function toItem(ContactNote $note): ContactNoteItem
    {
        return new ContactNoteItem(
            id: (int) $note->getId(),
            text: $note->getText(),
            createdAt: $note->getCreatedAt(),
            authorId: $note->getAuthorId(),
            authorName: $note->getAuthorName(),
            authorAvatar: $note->getAuthorAvatar(),
            parentId: $note->getParentNote()?->getId(),
        );
    }
}
