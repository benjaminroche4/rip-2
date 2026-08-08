<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDetails;
use App\Dossier\Domain\DossierPersonView;
use App\Dossier\Domain\DossierSearchView;
use App\Dossier\Domain\DossierSummary;
use App\Contact\Entity\Contact;
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
            ->leftJoin('p.documents', 'doc')
            ->addSelect('doc')
            ->leftJoin('d.manager', 'm')
            ->addSelect('m')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $offersByContactReference = $this->findOffersByContactReference($dossiers);

        $summaries = [];
        foreach ($dossiers as $dossier) {
            $primaryName = null;
            foreach ($dossier->getPersons() as $person) {
                if ($person->isPrimaryContact()) {
                    $primaryName = trim($person->getFirstName().' '.$person->getLastName());
                    break;
                }
            }

            $manager = $dossier->getManager();
            $managerName = null;
            if (null !== $manager) {
                $managerName = trim(($manager->getFirstName() ?? '').' '.($manager->getLastName() ?? ''));
                $managerName = '' !== $managerName ? $managerName : (string) $manager->getEmail();
            }

            $summaries[] = new DossierSummary(
                id: (int) $dossier->getId(),
                name: (string) $dossier->getName(),
                reference: (string) $dossier->getReference(),
                primaryTenantName: $primaryName,
                personCount: $dossier->getPersons()->count(),
                createdAt: $dossier->getCreatedAt() ?? new \DateTimeImmutable(),
                status: $dossier->getEffectiveStatus(),
                managerName: $managerName,
                managerAvatarFilename: $manager?->getAvatarFilename(),
                offer: $offersByContactReference[$dossier->getSourceContactReference()] ?? null,
            );
        }

        return $summaries;
    }

    /**
     * Package chosen on the source contacts, keyed by contact reference. One
     * query for the whole list; dossiers created from scratch have no source
     * contact and simply miss from the map.
     *
     * @param list<Dossier> $dossiers
     *
     * @return array<string, string>
     */
    private function findOffersByContactReference(array $dossiers): array
    {
        $references = [];
        foreach ($dossiers as $dossier) {
            if (null !== $dossier->getSourceContactReference()) {
                $references[] = $dossier->getSourceContactReference();
            }
        }

        if ([] === $references) {
            return [];
        }

        /** @var list<array{reference: string, offer: string|null}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT c.reference, c.offer FROM '.Contact::class.' c WHERE c.reference IN (:references)'
        )
            ->setParameter('references', $references)
            ->getArrayResult();

        $offers = [];
        foreach ($rows as $row) {
            if (null !== $row['offer']) {
                $offers[$row['reference']] = $row['offer'];
            }
        }

        return $offers;
    }

    /**
     * References of the previous / next dossier in list order (newest
     * first), driving the detail-page navigation arrows.
     *
     * @return array{previous: ?string, next: ?string}
     */
    public function findAdjacentReferences(string $reference): array
    {
        /** @var list<array{reference: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.reference')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getScalarResult();

        $references = array_column($rows, 'reference');
        $index = array_search($reference, $references, true);
        if (false === $index) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $references[$index - 1] ?? null,
            'next' => $references[$index + 1] ?? null,
        ];
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
                profession: $person->getProfession(),
                monthlyIncome: $person->getMonthlyIncome(),
                birthDate: $person->getBirthDate(),
                nationality: $person->getNationality(),
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
            $notes[] = DossierNoteRepository::toView($note);
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
