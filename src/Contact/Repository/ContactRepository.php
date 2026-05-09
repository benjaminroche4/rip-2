<?php

namespace App\Contact\Repository;

use App\Contact\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
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
