<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Dossier\Domain\DossierNoteView;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierNote>
 */
class DossierNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierNote::class);
    }

    /**
     * Follow-up thread for a dossier, newest first.
     *
     * @return list<DossierNoteView>
     */
    public function listForDossier(int $dossierId): array
    {
        /** @var list<DossierNote> $notes */
        $notes = $this->createQueryBuilder('n')
            ->andWhere('n.dossier = :dossierId')
            ->setParameter('dossierId', $dossierId)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(self::toView(...), $notes);
    }

    public function add(Dossier $dossier, string $text, int $authorId, string $authorName, ?string $authorAvatar): DossierNote
    {
        $note = (new DossierNote())
            ->setDossier($dossier)
            ->setText($text)
            ->setAuthorId($authorId)
            ->setAuthorName($authorName)
            ->setAuthorAvatar($authorAvatar);

        $em = $this->getEntityManager();
        $em->persist($note);
        $em->flush();

        return $note;
    }

    public function updateText(DossierNote $note, string $text): void
    {
        $note->setText($text);
        $this->getEntityManager()->flush();
    }

    public function remove(DossierNote $note): void
    {
        $em = $this->getEntityManager();
        $em->remove($note);
        $em->flush();
    }

    public static function toView(DossierNote $note): DossierNoteView
    {
        return new DossierNoteView(
            id: (int) $note->getId(),
            text: $note->getText(),
            createdAt: $note->getCreatedAt(),
            authorId: $note->getAuthorId(),
            authorName: $note->getAuthorName(),
            authorAvatar: $note->getAuthorAvatar(),
        );
    }
}
