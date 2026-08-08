<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Appends an audit entry to the dossier's follow-up thread. The author
 * snapshot comes from the logged-in admin when there is one; public
 * actions (tenant deposits) pass an explicit display name instead. The
 * entry is persisted but not flushed: every caller already flushes its own
 * mutation, the event rides the same transaction.
 */
final readonly class DossierEventLogger
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(Dossier $dossier, string $kind, array $payload = [], ?string $authorName = null): void
    {
        $event = (new DossierEvent())
            ->setDossier($dossier)
            ->setKind($kind)
            ->setPayload($payload);

        $user = $this->security->getUser();
        if (null === $authorName && $user instanceof User) {
            $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));
            $event->setAuthorName('' !== $fullName ? $fullName : (string) $user->getEmail());
            $event->setAuthorAvatar($user->getAvatarFilename());
        } else {
            $event->setAuthorName($authorName);
        }

        $this->em->persist($event);
    }
}
