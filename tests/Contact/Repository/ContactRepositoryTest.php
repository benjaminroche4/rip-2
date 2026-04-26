<?php

namespace App\Tests\Contact\Repository;

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

    private function persistContact(\DateTimeImmutable $createdAt): void
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
    }
}
