<?php

declare(strict_types=1);

namespace App\Dossier\Repository;

use App\Contact\Entity\Contact;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDetails;
use App\Dossier\Domain\DossierPersonView;
use App\Dossier\Domain\DossierSearchView;
use App\Dossier\Domain\DossierSummary;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\ExpiredPairingCodeAlert;
use App\Dossier\Domain\LinkedDossierSummary;
use App\Dossier\Domain\MoveInTimeline;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Entity\DossierNote;
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
     * Dossiers proposables à la planification d'une visite : ouverts ET
     * recherche complète (une visite se prépare sur les critères validés).
     * Le tri alphabétique suit le dropdown du formulaire de visite ;
     * DossierSearch::isComplete() reste l'unique source de vérité, d'où le
     * filtre en PHP plutôt qu'une réplication des sept critères en DQL.
     *
     * @return list<Dossier>
     */
    public function findOpenWithCompleteSearch(): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->leftJoin('d.search', 's')
            ->addSelect('s')
            ->where('d.closedAt IS NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $dossiers,
            static fn (Dossier $dossier): bool => $dossier->getSearch()?->isComplete() ?? false,
        ));
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
            ->leftJoin('d.search', 's')
            ->addSelect('s')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $offersByContactReference = $this->findOffersByContactReference($dossiers);

        $now = new \DateTimeImmutable();

        return array_map(
            fn (Dossier $dossier): DossierSummary => $this->toSummary($dossier, $offersByContactReference, $now),
            $dossiers,
        );
    }

    /**
     * Same read model for a single dossier: feeds the recap cards of the
     * visit booking form once a dossier is picked.
     */
    public function findSummaryById(int $id): ?DossierSummary
    {
        $dossier = $this->find($id);
        if (null === $dossier) {
            return null;
        }

        return $this->toSummary($dossier, $this->findOffersByContactReference([$dossier]), new \DateTimeImmutable());
    }

    /**
     * @param array<string, string> $offersByContactReference
     */
    private function toSummary(Dossier $dossier, array $offersByContactReference, \DateTimeImmutable $now): DossierSummary
    {
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

        $createdAt = $dossier->getCreatedAt() ?? $now;
        $moveInAt = $dossier->getSearch()?->getMoveInAt();

        return new DossierSummary(
            id: (int) $dossier->getId(),
            name: (string) $dossier->getName(),
            reference: (string) $dossier->getReference(),
            primaryTenantName: $primaryName,
            personCount: $dossier->getPersons()->count(),
            createdAt: $createdAt,
            status: $dossier->getEffectiveStatus(),
            managerId: null !== $manager ? (int) $manager->getId() : null,
            managerName: $managerName,
            managerAvatarFilename: $manager?->getAvatarFilename(),
            offer: $dossier->getOffer() ?? $offersByContactReference[$dossier->getSourceContactReference()] ?? null,
            moveInAt: $moveInAt,
            timeline: null !== $moveInAt ? MoveInTimeline::fromDates($createdAt, $moveInAt, $now) : null,
            searchComplete: $dossier->getSearch()?->isComplete() ?? false,
            searchAreas: $dossier->getSearch()?->getAreas(),
            closedAt: $dossier->getClosedAt(),
        );
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
     * Dossiers whose stored AI recap no longer reflects the file: a note or
     * an audit event landed after the recap was generated. Closed dossiers
     * and dossiers nobody ever generated a recap for are left alone, so the
     * nightly refresh only pays for files that actually moved.
     *
     * Oldest recap first: when the run hits its limit, the most outdated
     * ones are the ones that got refreshed.
     *
     * @return list<Dossier>
     */
    public function findWithStaleRecap(int $limit, bool $force = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.recapJson IS NOT NULL')
            ->andWhere('d.closedAt IS NULL')
            ->orderBy('d.recapGeneratedAt', 'ASC')
            ->setMaxResults($limit);

        if (!$force) {
            $qb->andWhere(
                'd.recapGeneratedAt IS NULL'
                .' OR EXISTS (SELECT n.id FROM '.DossierNote::class.' n WHERE n.dossier = d AND n.createdAt > d.recapGeneratedAt)'
                .' OR EXISTS (SELECT e.id FROM '.DossierEvent::class.' e WHERE e.dossier = d AND e.createdAt > d.recapGeneratedAt)'
            );
        }

        /** @var list<Dossier> $dossiers */
        $dossiers = $qb->getQuery()->getResult();

        return $dossiers;
    }

    /**
     * Open dossiers whose pairing code expired (never armed, or last armed
     * before the threshold) while pieces are still awaiting a deposit
     * (requested or refused): the emailed deposit link is dead, staff should
     * resend the document request to re-arm the code. A dossier whose pieces
     * are all validated (or received) does not need a live code and stays
     * out of the alert. One scalar query, oldest first, no N+1.
     *
     * @return list<ExpiredPairingCodeAlert>
     */
    public function findExpiredPairingAlerts(\DateTimeImmutable $threshold): array
    {
        /** @var list<array{reference: string, name: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.reference', 'd.name')
            ->where('d.closedAt IS NULL')
            ->andWhere('d.pairingCodeSentAt IS NULL OR d.pairingCodeSentAt < :threshold')
            ->andWhere('EXISTS (
                SELECT doc.id FROM '.\App\Dossier\Entity\DossierDocument::class.' doc
                JOIN doc.person p
                WHERE p.dossier = d AND doc.status IN (:awaiting)
            )')
            ->setParameter('threshold', $threshold)
            ->setParameter('awaiting', [
                \App\Dossier\Domain\DossierDocumentStatus::Requested->value,
                \App\Dossier\Domain\DossierDocumentStatus::Refused->value,
            ])
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): ExpiredPairingCodeAlert => new ExpiredPairingCodeAlert($row['reference'], $row['name']),
            $rows,
        );
    }

    /** Number of dossiers currently open (not closed), for the sidebar badge. */
    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.closedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
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
     * Every dossier carrying this email on one of its persons, feeding the
     * "linked dossiers" banner of the lead page. One scalar query, no N+1,
     * case-insensitive on the email; a dossier whose two persons would share
     * the email only appears once (first person in display order wins).
     * Converted-from-this-lead first, then newest first.
     *
     * @return list<LinkedDossierSummary>
     */
    public function findByPersonEmail(string $email, ?string $contactReference = null): array
    {
        $email = trim($email);
        if ('' === $email) {
            return [];
        }

        /** @var list<array{reference: string, name: string, role: DossierPersonRole|string, closedAt: \DateTimeImmutable|null, sourceContactReference: string|null}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.reference', 'd.name', 'p.role', 'd.closedAt', 'd.sourceContactReference')
            ->join('d.persons', 'p')
            ->where('LOWER(p.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('p.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $summaries = [];
        foreach ($rows as $row) {
            if (isset($summaries[$row['reference']])) {
                continue;
            }

            $summaries[$row['reference']] = new LinkedDossierSummary(
                reference: $row['reference'],
                name: $row['name'],
                personRole: $row['role'] instanceof DossierPersonRole ? $row['role'] : DossierPersonRole::from($row['role']),
                closed: null !== $row['closedAt'],
                fromThisContact: null !== $contactReference && $row['sourceContactReference'] === $contactReference,
            );
        }

        // The dossier born from this lead is the headline entry of the banner.
        usort($summaries, static fn (LinkedDossierSummary $a, LinkedDossierSummary $b): int => $b->fromThisContact <=> $a->fromThisContact);

        return $summaries;
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
