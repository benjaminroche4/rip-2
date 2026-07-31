<?php

namespace App\Contact\Repository;

use App\Contact\Domain\ClosureReason;
use App\Auth\Entity\User;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Domain\StayDuration;
use App\Contact\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ContactEventRepository $events,
    ) {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Returns the most recent contact submissions as read models, newest
     * first. Used by the admin contact list ("load more" pagination: the
     * component always re-fetches the first N rows).
     *
     * @return list<ContactListItem>
     */
    public function listFirst(int $limit, ?ContactStatus $status = null, ?string $search = null): array
    {
        // Newest first, whatever the filter.
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);

        $this->applyFilters($qb, $status, $search);

        /** @var list<Contact> $contacts */
        $contacts = $qb->getQuery()->getResult();

        return array_map($this->toListItem(...), $contacts);
    }

    /**
     * Count matching the same filters as listFirst() — powers "load more".
     */
    public function countFiltered(?ContactStatus $status = null, ?string $search = null): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)');
        $this->applyFilters($qb, $status, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, ?ContactStatus $status, ?string $search): void
    {
        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        if (null !== $search && '' !== trim($search)) {
            $like = '%'.addcslashes(trim($search), '%_').'%';
            $qb->andWhere("c.firstName LIKE :search OR c.lastName LIKE :search OR c.email LIKE :search OR c.reference LIKE :search OR CONCAT(c.firstName, ' ', c.lastName) LIKE :search")
                ->setParameter('search', $like);
        }
    }

    /**
     * Fetches the given ids and returns them in the exact order given.
     * Lets the admin list keep its on-screen order stable across live
     * re-renders (a treated card must not jump around mid-interaction).
     *
     * @param list<int> $ids
     *
     * @return list<ContactListItem>
     */
    public function listByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Contact> $contacts */
        $contacts = $this->createQueryBuilder('c')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($contacts as $contact) {
            $byId[(int) $contact->getId()] = $this->toListItem($contact);
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * References of the submissions adjacent to $id in the list ordering
     * (newest first): "newer" is the previous card, "older" the next one.
     *
     * @return array{newer: ?string, older: ?string}
     */
    public function adjacentReferences(int $id): array
    {
        $current = $this->find($id);
        if (null === $current) {
            return ['newer' => null, 'older' => null];
        }

        // Navigation stays within the same status: an admin working through
        // "À traiter" jumps to the next untreated request, not to any contact.
        $newer = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.createdAt > :date OR (c.createdAt = :date AND c.id > :id)')
            ->setParameter('status', $current->getStatus())
            ->setParameter('date', $current->getCreatedAt())
            ->setParameter('id', $id)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $older = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.createdAt < :date OR (c.createdAt = :date AND c.id < :id)')
            ->setParameter('status', $current->getStatus())
            ->setParameter('date', $current->getCreatedAt())
            ->setParameter('id', $id)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'newer' => $newer?->getReference(),
            'older' => $older?->getReference(),
        ];
    }

    public function findOneItemByReference(string $reference): ?ContactListItem
    {
        $contact = $this->findOneBy(['reference' => $reference]);

        return null !== $contact ? $this->toListItem($contact) : null;
    }

    /**
     * Assigns (or unassigns with null) the team member following the lead.
     */
    public function assign(int $id, ?User $assignee): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setAssignedTo($assignee);
        $this->getEntityManager()->flush();
    }

    private function toListItem(Contact $c): ContactListItem
    {
        $assignee = $c->getAssignedTo();
        $assigneeName = null;
        if (null !== $assignee) {
            $assigneeName = trim(($assignee->getFirstName() ?? '').' '.($assignee->getLastName() ?? '')) ?: $assignee->getEmail();
        }

        return new ContactListItem(
            id: (int) $c->getId(),
            firstName: (string) $c->getFirstName(),
            lastName: (string) $c->getLastName(),
            email: (string) $c->getEmail(),
            phoneNumber: $c->getPhoneNumber(),
            company: $c->getCompany(),
            helpType: (string) $c->getHelpType(),
            message: $c->getMessage(),
            createdAt: $c->getCreatedAt() ?? new \DateTimeImmutable(),
            lang: (string) $c->getLang(),
            reference: $c->getReference(),
            status: $c->getStatus(),
            statusChangedBy: $c->getStatusChangedBy(),
            statusChangedByAvatar: $c->getStatusChangedByAvatar(),
            ip: $c->getIp(),
            statusChangedAt: $c->getStatusChangedAt(),
            firstTreatedAt: $c->getFirstTreatedAt(),
            leadRating: $c->getLeadRating(),
            leadNote: $c->getLeadNote(),
            offer: $c->getOffer(),
            recontactChannel: $c->getRecontactChannel(),
            recallAt: $c->getRecallAt(),
            closureReason: $c->getClosureReason(),
            nextStep: $c->getNextStep(),
            leadSource: $c->getLeadSource(),
            projectBudget: $c->getProjectBudget(),
            projectAreas: $c->getProjectAreas(),
            projectMoveInAt: $c->getProjectMoveInAt(),
            projectPropertyType: $c->getProjectPropertyType(),
            projectStayDuration: $c->getProjectStayDuration(),
            assigneeId: null !== $assignee ? (int) $assignee->getId() : null,
            assigneeName: $assigneeName,
            assigneeAvatar: $assignee?->getAvatarFilename(),
        );
    }

    public function countByStatus(ContactStatus $status): int
    {
        return $this->count(['status' => $status]);
    }

    /**
     * Counts per status, every case present (0 when empty). Keyed by the
     * enum backed value.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach (ContactStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status, COUNT(c.id) AS total')
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $value = $row['status'] instanceof ContactStatus ? $row['status']->value : (string) $row['status'];
            $counts[$value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Changes the lifecycle status of a submission, recording who did it and
     * when. Silently ignores unknown ids: the admin list may be stale (row
     * deleted in another tab) and a 500 on a status click would be worse
     * than a no-op.
     *
     * Returns true when this change is the submission's FIRST treatment
     * (it just left "new" for the first time) — the caller uses it to ask
     * for the lead-quality rating exactly once.
     */
    public function updateStatus(int $id, ContactStatus $status, ?string $changedBy = null, ?string $changedByAvatar = null): bool
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return false;
        }

        // Single instant for both timestamps so "first treatment" can be
        // told apart from later updates by comparing them.
        $now = new \DateTimeImmutable();
        $contact->setStatus($status)
            ->setStatusChangedBy($changedBy)
            ->setStatusChangedByAvatar($changedByAvatar)
            ->setStatusChangedAt($now);

        $firstTreatment = null === $contact->getFirstTreatedAt() && ContactStatus::New !== $status;
        if ($firstTreatment) {
            $contact->setFirstTreatedAt($now);
        }

        // The next step only makes sense while in progress.
        if (ContactStatus::InProgress !== $status) {
            $contact->setNextStep(null);
        }

        $this->events->record($contact, $status, null, $changedBy, $changedByAvatar);
        $this->getEntityManager()->flush();

        return $firstTreatment;
    }

    /**
     * Saves the 1-5 lead quality rating. Silently ignores unknown ids,
     * same tolerance as updateStatus().
     */
    public function saveLeadRating(int $id, int $rating): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setLeadRating($rating);
        $this->getEntityManager()->flush();
    }

    public function saveRecallAt(int $id, ?\DateTimeImmutable $recallAt): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        // A new recall date re-arms the reminder emails.
        $contact->setRecallAt($recallAt)
            ->setRecallReminderDaySentAt(null)
            ->setRecallReminderHourSentAt(null)
            ->setRecallReminderSoonSentAt(null);
        $this->getEntityManager()->flush();
    }

    /**
     * Contacts with an upcoming recall, assignee joined — the pool the
     * reminder cron works from.
     *
     * @return list<Contact>
     */
    public function findWithUpcomingRecall(\DateTimeImmutable $now): array
    {
        /** @var list<Contact> $contacts */
        $contacts = $this->createQueryBuilder('c')
            ->leftJoin('c.assignedTo', 'a')->addSelect('a')
            ->andWhere('c.recallAt IS NOT NULL')
            ->andWhere('c.recallAt > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $contacts;
    }

    public function saveClosureReason(int $id, ?ClosureReason $reason, ?string $changedBy = null, ?string $changedByAvatar = null): void
    {
        $contact = $this->find($id);
        if (null === $contact || $contact->getClosureReason() === $reason) {
            return;
        }

        $contact->setClosureReason($reason);
        if (null !== $reason) {
            $this->events->record($contact, null, $reason, $changedBy, $changedByAvatar);
        }
        $this->getEntityManager()->flush();
    }

    public function saveNextStep(int $id, ?NextStep $nextStep): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setNextStep($nextStep);
        $this->getEntityManager()->flush();
    }

    public function saveProject(int $id, ?int $budget, ?string $areas, ?\DateTimeImmutable $moveInAt, ?string $propertyType): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setProjectBudget($budget)
            ->setProjectAreas(null !== $areas && '' !== trim($areas) ? trim($areas) : null)
            ->setProjectMoveInAt($moveInAt)
            ->setProjectPropertyType(null !== $propertyType && '' !== trim($propertyType) ? trim($propertyType) : null);
        $this->getEntityManager()->flush();
    }

    public function saveStayDuration(int $id, ?StayDuration $duration): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setProjectStayDuration($duration);
        $this->getEntityManager()->flush();
    }

    /**
     * Other submissions sharing the same email, newest first — surfaces
     * returning leads on the detail page.
     *
     * @return list<ContactListItem>
     */
    public function listOtherByEmail(string $email, int $excludeId): array
    {
        /** @var list<Contact> $contacts */
        $contacts = $this->createQueryBuilder('c')
            ->andWhere('c.email = :email')
            ->andWhere('c.id != :id')
            ->setParameter('email', $email)
            ->setParameter('id', $excludeId)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map($this->toListItem(...), $contacts);
    }

    public function saveRecontactChannel(int $id, RecontactChannel $channel): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setRecontactChannel($channel);
        $this->getEntityManager()->flush();
    }

    public function saveLeadNote(int $id, ?string $note): void
    {
        $contact = $this->find($id);
        if (null === $contact) {
            return;
        }

        $contact->setLeadNote(null !== $note && '' !== trim($note) ? trim($note) : null);
        $this->getEntityManager()->flush();
    }

    /**
     * First-response stats over submissions received since $since:
     * average minutes between creation and first treatment, share treated
     * within the 30-minute SLA, and how many treated submissions the stats
     * are based on. Null metrics when nothing was treated in the window.
     *
     * @return array{avgMinutes: ?float, withinSlaRate: ?float, treatedCount: int}
     */
    public function responseTimeStats(\DateTimeImmutable $since): array
    {
        $row = $this->getEntityManager()->getConnection()
            ->executeQuery(
                'SELECT
                    AVG(TIMESTAMPDIFF(SECOND, created_at, first_treated_at)) AS avg_seconds,
                    AVG(TIMESTAMPDIFF(SECOND, created_at, first_treated_at) <= 1800) AS within_sla,
                    COUNT(*) AS treated
                 FROM contact
                 WHERE first_treated_at IS NOT NULL AND created_at >= :since',
                ['since' => $since->format('Y-m-d H:i:s')],
            )
            ->fetchAssociative();

        $treated = (int) ($row['treated'] ?? 0);

        return [
            'avgMinutes' => $treated > 0 ? ((float) $row['avg_seconds']) / 60 : null,
            'withinSlaRate' => $treated > 0 ? (float) $row['within_sla'] : null,
            'treatedCount' => $treated,
        ];
    }

    /**
     * Returns the contact request count grouped by year-month over the last
     * $monthsBack months (current month included). Empty months are filled
     * with 0 so the caller gets a contiguous time series ready to plot.
     *
     * @return list<array{ym: string, count: int}>
     */
    public function countByMonth(int $monthsBack = 12): array
    {
        $end = new \DateTimeImmutable('first day of next month 00:00:00');
        $start = $end->modify('-'.$monthsBack.' months');

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
                 FROM contact
                 WHERE created_at >= :start AND created_at < :end
                 GROUP BY ym",
                [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end->format('Y-m-d H:i:s'),
                ],
            )
            ->fetchAllAssociative();

        $byYm = array_column($rows, 'total', 'ym');

        $series = [];
        for ($i = $monthsBack; $i >= 1; --$i) {
            $ym = $end->modify('-'.$i.' months')->format('Y-m');
            $series[] = ['ym' => $ym, 'count' => (int) ($byYm[$ym] ?? 0)];
        }

        return $series;
    }

    /**
     * Returns the contact request count per day from the very first contact
     * up to today (inclusive). Days with no contact are filled with 0 so the
     * series is contiguous, ready to be plotted as a continuous time series.
     *
     * @return list<array{date: string, count: int}>
     */
    public function countByDayAllTime(): array
    {
        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery(
                "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS d, COUNT(*) AS total
                 FROM contact
                 GROUP BY d
                 ORDER BY d ASC",
            )
            ->fetchAllAssociative();

        if (empty($rows)) {
            return [];
        }

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = (int) $row['total'];
        }

        $first = new \DateTimeImmutable((string) array_key_first($byDay));
        $today = new \DateTimeImmutable('today');

        $series = [];
        for ($cursor = $first; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $series[] = ['date' => $key, 'count' => $byDay[$key] ?? 0];
        }

        return $series;
    }

    /**
     * Returns the total contact count distributed across the 7 weekdays
     * (1=Monday, 7=Sunday — ISO 8601). Reuses countByDayAllTime() so this
     * is essentially free; lets callers spot which weekday brings the most
     * contacts across the whole project history.
     *
     * @return array<int, int>
     */
    public function countByWeekdayAllTime(): array
    {
        $byWeekday = array_fill_keys(range(1, 7), 0);
        foreach ($this->countByDayAllTime() as $row) {
            $weekday = (int) (new \DateTimeImmutable($row['date']))->format('N');
            $byWeekday[$weekday] += $row['count'];
        }

        return $byWeekday;
    }

    /**
     * Returns the count of contact requests grouped by Y-m-d for the
     * [$from, $to) window. Result is keyed by the date string so the caller
     * can do O(1) lookups when stitching together a calendar view.
     *
     * @return array<string, int>
     */
    public function countByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery(
                "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS d, COUNT(*) AS total
                 FROM contact
                 WHERE created_at >= :from AND created_at < :to
                 GROUP BY d",
                [
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                ],
            )
            ->fetchAllAssociative();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = (int) $row['total'];
        }

        return $byDay;
    }
}
