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
}
