<?php

namespace App\Tests\Contact\Repository;

use App\Contact\Domain\ContactStatus;
use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContactRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ContactRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->repository = $container->get(ContactRepository::class);

        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
    }

    public function testCountByMonthReturnsContiguous12Buckets(): void
    {
        $series = $this->repository->countByMonth(12);

        self::assertCount(12, $series);
        foreach ($series as $row) {
            self::assertArrayHasKey('ym', $row);
            self::assertArrayHasKey('count', $row);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $row['ym']);
            self::assertSame(0, $row['count']);
        }
    }

    public function testCountByMonthAggregatesContactsInTheirRespectiveBucket(): void
    {
        $thisMonth = new \DateTimeImmutable('first day of this month 12:00:00');
        $lastMonth = $thisMonth->modify('-1 month');
        $twoMonthsAgo = $thisMonth->modify('-2 months');

        $this->persistContact($thisMonth);
        $this->persistContact($thisMonth);
        $this->persistContact($lastMonth);
        $this->persistContact($twoMonthsAgo);

        $this->em->flush();

        $series = $this->repository->countByMonth(12);
        $byYm = array_column($series, 'count', 'ym');

        self::assertSame(2, $byYm[$thisMonth->format('Y-m')]);
        self::assertSame(1, $byYm[$lastMonth->format('Y-m')]);
        self::assertSame(1, $byYm[$twoMonthsAgo->format('Y-m')]);
    }

    public function testCountByMonthExcludesContactsOlderThanWindow(): void
    {
        $thirteenMonthsAgo = (new \DateTimeImmutable('first day of this month 12:00:00'))->modify('-13 months');

        $this->persistContact($thirteenMonthsAgo);
        $this->em->flush();

        $series = $this->repository->countByMonth(12);
        $totals = array_sum(array_column($series, 'count'));

        self::assertSame(0, $totals);
    }

    public function testCountByDayKeyedByDateInsideWindow(): void
    {
        $today = new \DateTimeImmutable('today 12:00:00');
        $yesterday = $today->modify('-1 day');
        $beforeWindow = $today->modify('-30 days');

        $this->persistContact($today);
        $this->persistContact($today);
        $this->persistContact($yesterday);
        $this->persistContact($beforeWindow);

        $this->em->flush();

        $from = $today->modify('-7 days')->setTime(0, 0);
        $to = $today->modify('+1 day')->setTime(0, 0);

        $byDay = $this->repository->countByDay($from, $to);

        self::assertSame(2, $byDay[$today->format('Y-m-d')]);
        self::assertSame(1, $byDay[$yesterday->format('Y-m-d')]);
        self::assertArrayNotHasKey($beforeWindow->format('Y-m-d'), $byDay);
    }

    public function testCountByDayAllTimeReturnsEmptyWhenNoContacts(): void
    {
        self::assertSame([], $this->repository->countByDayAllTime());
    }

    public function testCountByDayAllTimeFillsGapsBetweenFirstContactAndToday(): void
    {
        $today = new \DateTimeImmutable('today 12:00:00');
        $threeDaysAgo = $today->modify('-3 days');
        $oneDayAgo = $today->modify('-1 day');

        // 2 on day -3, 0 on day -2, 1 on day -1, 0 today.
        $this->persistContact($threeDaysAgo);
        $this->persistContact($threeDaysAgo);
        $this->persistContact($oneDayAgo);
        $this->em->flush();

        $series = $this->repository->countByDayAllTime();

        self::assertCount(4, $series, 'series should span first contact to today, inclusive');
        self::assertSame($threeDaysAgo->format('Y-m-d'), $series[0]['date']);
        self::assertSame(2, $series[0]['count']);
        self::assertSame(0, $series[1]['count']);
        self::assertSame(1, $series[2]['count']);
        self::assertSame($today->format('Y-m-d'), $series[3]['date']);
        self::assertSame(0, $series[3]['count']);
    }

    public function testCountByWeekdayAllTimeAggregatesAcrossWeekdays(): void
    {
        // Pick a known Monday to anchor the assertions deterministically.
        $monday = new \DateTimeImmutable('2024-01-01 12:00:00'); // ISO Monday
        $tuesday = $monday->modify('+1 day');
        $sunday = $monday->modify('+6 days');

        $this->persistContact($monday);
        $this->persistContact($monday);
        $this->persistContact($tuesday);
        $this->persistContact($sunday);
        $this->em->flush();

        $byWeekday = $this->repository->countByWeekdayAllTime();

        // All 7 ISO weekday slots present, days without contacts are 0.
        self::assertCount(7, $byWeekday);
        self::assertSame(2, $byWeekday[1]); // Monday
        self::assertSame(1, $byWeekday[2]); // Tuesday
        self::assertSame(0, $byWeekday[3]);
        self::assertSame(1, $byWeekday[7]); // Sunday
    }

    public function testListFirstSearchesNameEmailAndReference(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        $lea = $this->persistContact($now)->setFirstName('Léa')->setLastName('Dupont')->setReference('CT-111222');
        $this->persistContact($now->modify('-1 hour'))->setReference('CT-333444');
        $this->em->flush();

        self::assertCount(1, $this->repository->listFirst(10, null, 'dupont'));
        self::assertCount(1, $this->repository->listFirst(10, null, 'CT-111'));
        self::assertSame($lea->getId(), $this->repository->listFirst(10, null, 'léa dupont')[0]->id);
        self::assertCount(0, $this->repository->listFirst(10, null, 'introuvable'));
        self::assertSame(1, $this->repository->countFiltered(null, 'dupont'));
        self::assertSame(2, $this->repository->countFiltered(null, null));
    }

    public function testAdjacentReferencesFollowTheListOrdering(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        $oldest = $this->persistContact($now->modify('-2 hours'))->setReference('CT-000003');
        $middle = $this->persistContact($now->modify('-1 hour'))->setReference('CT-000002');
        $newest = $this->persistContact($now)->setReference('CT-000001');
        $this->em->flush();

        $adjacent = $this->repository->adjacentReferences((int) $middle->getId());
        self::assertSame('CT-000001', $adjacent['newer']);
        self::assertSame('CT-000003', $adjacent['older']);

        self::assertNull($this->repository->adjacentReferences((int) $newest->getId())['newer']);
        self::assertNull($this->repository->adjacentReferences((int) $oldest->getId())['older']);
    }

    public function testStatusAndClosureReasonChangesAreRecordedAsEvents(): void
    {
        $contact = $this->persistContact(new \DateTimeImmutable('today 10:00'));
        $this->em->flush();
        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class);

        $this->repository->updateStatus((int) $contact->getId(), ContactStatus::InProgress, 'Julien Moreau', null);
        $this->repository->saveClosureReason((int) $contact->getId(), \App\Contact\Domain\ClosureReason::Unreachable, 'Julien Moreau', null);
        // Saving the same reason twice does not duplicate the event.
        $this->repository->saveClosureReason((int) $contact->getId(), \App\Contact\Domain\ClosureReason::Unreachable, 'Julien Moreau', null);

        $items = $events->listForContact((int) $contact->getId());
        self::assertCount(2, $items);
        self::assertSame(\App\Contact\Domain\ClosureReason::Unreachable, $items[0]->closureReason);
        self::assertNull($items[0]->status);
        self::assertSame(ContactStatus::InProgress, $items[1]->status);
        self::assertSame('Julien Moreau', $items[1]->authorName);
    }

    public function testAdjacentReferencesStayWithinTheSameStatus(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        $oldConverted = $this->persistContact($now->modify('-3 hours'))->setReference('CT-000004')->setStatus(ContactStatus::Converted);
        $this->persistContact($now->modify('-2 hours'))->setReference('CT-000003'); // status "new", ignored
        $current = $this->persistContact($now->modify('-1 hour'))->setReference('CT-000002')->setStatus(ContactStatus::Converted);
        $this->persistContact($now)->setReference('CT-000001'); // status "new", ignored
        $this->em->flush();

        $adjacent = $this->repository->adjacentReferences((int) $current->getId());
        self::assertNull($adjacent['newer'], 'No newer converted request.');
        self::assertSame('CT-000004', $adjacent['older']);

        self::assertNull($this->repository->adjacentReferences((int) $oldConverted->getId())['older']);
    }

    public function testListOtherByEmailFindsSiblingsNewestFirst(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        $first = $this->persistContact($now->modify('-2 days'))->setEmail('same@example.com');
        $second = $this->persistContact($now->modify('-1 day'))->setEmail('same@example.com');
        $current = $this->persistContact($now)->setEmail('same@example.com');
        $this->persistContact($now)->setEmail('other@example.com');
        $this->em->flush();

        $others = $this->repository->listOtherByEmail('same@example.com', (int) $current->getId());

        self::assertSame([(int) $second->getId(), (int) $first->getId()], array_map(static fn ($i) => $i->id, $others));
    }

    public function testCountsByStatusFillsEveryCase(): void
    {
        $this->persistContact(new \DateTimeImmutable('today 10:00'));
        $this->persistContact(new \DateTimeImmutable('today 11:00'));
        $this->em->flush();

        $counts = $this->repository->countsByStatus();

        self::assertSame(2, $counts['new']);
        self::assertSame(0, $counts['closed']);
        self::assertCount(6, $counts);
    }

    public function testListFirstOrdersNewestFirst(): void
    {
        $now = new \DateTimeImmutable('today 12:00');

        $oldNew = $this->persistContact($now->modify('-3 hours'));
        $freshNew = $this->persistContact($now->modify('-10 minutes'));
        $recentTreated = $this->persistContact($now->modify('-1 hour'))->setStatus(ContactStatus::InProgress);
        $oldTreated = $this->persistContact($now->modify('-5 hours'))->setStatus(ContactStatus::Closed);
        $this->em->flush();

        $ids = array_map(static fn ($item) => $item->id, $this->repository->listFirst(10));

        self::assertSame([
            $freshNew->getId(),
            $recentTreated->getId(),
            $oldNew->getId(),
            $oldTreated->getId(),
        ], $ids);
    }

    public function testUpdateStatusSetsFirstTreatedAtOnlyOnce(): void
    {
        $contact = $this->persistContact(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->repository->updateStatus((int) $contact->getId(), ContactStatus::InProgress);
        $firstTreatedAt = $contact->getFirstTreatedAt();
        self::assertNotNull($firstTreatedAt);

        $this->repository->updateStatus((int) $contact->getId(), ContactStatus::Closed);
        self::assertSame($firstTreatedAt, $contact->getFirstTreatedAt());
    }

    public function testUpdateStatusBackToNewDoesNotSetFirstTreatedAt(): void
    {
        $contact = $this->persistContact(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->repository->updateStatus((int) $contact->getId(), ContactStatus::New);

        self::assertNull($contact->getFirstTreatedAt());
    }

    public function testResponseTimeStatsAveragesTreatedSubmissions(): void
    {
        $now = new \DateTimeImmutable('now');

        // Treated in 10 min (within SLA) and 50 min (outside SLA).
        $this->persistContact($now->modify('-2 hours'))
            ->setStatus(ContactStatus::InProgress)
            ->setFirstTreatedAt($now->modify('-2 hours')->modify('+10 minutes'));
        $this->persistContact($now->modify('-3 hours'))
            ->setStatus(ContactStatus::Closed)
            ->setFirstTreatedAt($now->modify('-3 hours')->modify('+50 minutes'));
        // Untreated: excluded from the stats.
        $this->persistContact($now->modify('-1 hour'));
        $this->em->flush();

        $stats = $this->repository->responseTimeStats($now->modify('-30 days'));

        self::assertSame(2, $stats['treatedCount']);
        self::assertEqualsWithDelta(30.0, $stats['avgMinutes'], 0.1);
        self::assertEqualsWithDelta(0.5, $stats['withinSlaRate'], 0.001);
    }

    public function testResponseTimeStatsAreNullWhenNothingTreated(): void
    {
        $this->persistContact(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $stats = $this->repository->responseTimeStats(new \DateTimeImmutable('-30 days'));

        self::assertSame(0, $stats['treatedCount']);
        self::assertNull($stats['avgMinutes']);
        self::assertNull($stats['withinSlaRate']);
    }

    private function persistContact(\DateTimeImmutable $createdAt): Contact
    {
        $contact = (new Contact())
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt($createdAt);

        $this->em->persist($contact);

        return $contact;
    }
}
