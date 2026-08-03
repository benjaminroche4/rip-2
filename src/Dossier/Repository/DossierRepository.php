<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDetails;
use App\Dossier\Domain\DossierNoteView;
use App\Dossier\Domain\DossierPersonView;
use App\Dossier\Domain\DossierSearchView;
use App\Dossier\Domain\DossierSummary;
use App\Dossier\Entity\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    /**
     * Read model for the admin list, newest first.
     *
     * @return list<DossierSummary>
     */
    public function findSummaries(): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->leftJoin('d.persons', 'p')
            ->addSelect('p')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $summaries = [];
        foreach ($dossiers as $dossier) {
            $primaryName = null;
            foreach ($dossier->getPersons() as $person) {
                if ($person->isPrimaryContact()) {
                    $primaryName = trim($person->getFirstName().' '.$person->getLastName());
                    break;
                }
            }

            $summaries[] = new DossierSummary(
                id: (int) $dossier->getId(),
                name: (string) $dossier->getName(),
                reference: (string) $dossier->getReference(),
                primaryTenantName: $primaryName,
                personCount: $dossier->getPersons()->count(),
                createdAt: $dossier->getCreatedAt() ?? new \DateTimeImmutable(),
            );
        }

        return $summaries;
    }

    /**
     * Dossier whose primary tenant carries this email — used to keep the
     * contact → dossier conversion idempotent.
     */
    public function findByPrimaryTenantEmail(string $email): ?Dossier
    {
        return $this->createQueryBuilder('d')
            ->join('d.persons', 'p')
            ->where('p.email = :email')
            ->andWhere('p.primaryContact = true')
            ->setParameter('email', $email)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Read model for the detail page, persons in display order.
     */
    public function findDetailsByReference(string $reference): ?DossierDetails
    {
        /** @var Dossier|null $dossier */
        $dossier = $this->createQueryBuilder('d')
            ->leftJoin('d.persons', 'p')
            ->addSelect('p')
            ->where('d.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $dossier) {
            return null;
        }

        $persons = [];
        foreach ($dossier->getPersons() as $person) {
            $persons[] = new DossierPersonView(
                id: (int) $person->getId(),
                role: $person->getRole() ?? throw new \LogicException('Persisted person without role.'),
                firstName: (string) $person->getFirstName(),
                lastName: (string) $person->getLastName(),
                email: (string) $person->getEmail(),
                phone: $person->getPhone(),
                language: $person->getLanguage() ?? ContactLanguage::FR,
                primaryContact: $person->isPrimaryContact(),
            );
        }

        $search = $dossier->getSearch();
        $searchView = null !== $search ? new DossierSearchView(
            budget: $search->getBudget(),
            areas: $search->getAreas(),
            moveInAt: $search->getMoveInAt(),
            propertyType: $search->getPropertyType(),
            stayDuration: $search->getStayDuration(),
            furnishing: $search->getFurnishing(),
            guarantorType: $search->getGuarantorType(),
            note: $search->getNote(),
        ) : null;

        $notes = [];
        foreach ($dossier->getNotes() as $note) {
            $notes[] = new DossierNoteView(
                text: $note->getText(),
                createdAt: $note->getCreatedAt(),
                authorName: $note->getAuthorName(),
                authorAvatar: $note->getAuthorAvatar(),
            );
        }

        return new DossierDetails(
            id: (int) $dossier->getId(),
            name: (string) $dossier->getName(),
            reference: (string) $dossier->getReference(),
            pairingCode: (string) $dossier->getPairingCode(),
            createdAt: $dossier->getCreatedAt() ?? new \DateTimeImmutable(),
            persons: $persons,
            search: $searchView,
            notes: $notes,
            sourceContactReference: $dossier->getSourceContactReference(),
        );
    }
}
