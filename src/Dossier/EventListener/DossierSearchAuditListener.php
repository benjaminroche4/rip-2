<?php

declare(strict_types=1);

namespace App\Dossier\EventListener;

use App\Auth\Entity\User;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Entity\DossierSearch;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Field-level audit of the search criteria: every persisted change on
 * DossierSearch (budget, move-in date, chips, ...) lands in the follow-up
 * thread with its new value, without each editor action having to log
 * anything. Hooking the Doctrine flush guarantees nothing is ever missed,
 * including future fields.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class DossierSearchAuditListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(DossierEvent::class);

        // Insertions too: the very first criterion creates the search row,
        // and that first change must be audited like any other.
        $entities = [...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityInsertions()];
        foreach ($entities as $entity) {
            if (!$entity instanceof DossierSearch) {
                continue;
            }
            $dossier = $entity->getDossier();
            if (null === $dossier) {
                continue;
            }
            $isInsertion = \in_array($entity, $uow->getScheduledEntityInsertions(), true);

            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                if ('dossier' === $field) {
                    continue;
                }
                // A fresh row initialises every column: only the fields the
                // admin actually filled are worth an entry.
                if ($isInsertion && '' === $this->format($change[1] ?? null)) {
                    continue;
                }
                $event = (new DossierEvent())
                    ->setDossier($dossier)
                    ->setKind('search_updated')
                    ->setPayload([
                        'field' => 'admin.dossiers.show.events.searchField.'.$field,
                        'old' => $this->format($change[0] ?? null),
                        'value' => $this->format($change[1] ?? null),
                    ]);

                $user = $this->security->getUser();
                if ($user instanceof User) {
                    $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));
                    $event->setAuthorName('' !== $fullName ? $fullName : (string) $user->getEmail());
                    $event->setAuthorAvatar($user->getAvatarFilename());
                }

                $em->persist($event);
                $uow->computeChangeSet($metadata, $event);
            }
        }
    }

    private function format(mixed $value): string
    {
        return match (true) {
            null === $value, '' === $value => '',
            $value instanceof \DateTimeInterface => $value->format('d.m.Y'),
            \is_array($value) => \sprintf('%d', \count($value)),
            \is_bool($value) => $value ? '1' : '0',
            default => mb_substr((string) $value, 0, 200),
        };
    }
}
