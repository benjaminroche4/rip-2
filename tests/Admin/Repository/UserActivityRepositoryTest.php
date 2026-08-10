<?php

namespace App\Tests\Admin\Repository;

use App\Admin\Repository\UserActivityRepository;
use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-DB checks for the profile activity lists: assigned leads and managed
 * dossiers of a staff member, newest first, scoped to that member only.
 */
final class UserActivityRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserActivityRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->repo = $container->get(UserActivityRepository::class);

        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        foreach ($this->em->getRepository(Dossier::class)->findAll() as $dossier) {
            $this->em->remove($dossier);
        }
        $this->em->flush();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-activity-test.local')->execute();
    }

    public function testAssignedLeadsAreScopedToTheUserAndNewestFirst(): void
    {
        $member = $this->persistUser('member@user-activity-test.local');
        $other = $this->persistUser('other@user-activity-test.local');

        $this->persistContact('Old', 'Lead', new \DateTimeImmutable('2026-01-01'), $member);
        $this->persistContact('New', 'Lead', new \DateTimeImmutable('2026-05-01'), $member);
        $this->persistContact('Foreign', 'Lead', new \DateTimeImmutable('2026-06-01'), $other);
        $this->persistContact('Orphan', 'Lead', new \DateTimeImmutable('2026-06-01'), null);
        $this->em->flush();

        $items = $this->repo->assignedLeads((int) $member->getId());

        self::assertCount(2, $items);
        self::assertSame('New Lead', $items[0]->label);
        self::assertSame('Old Lead', $items[1]->label);
        self::assertMatchesRegularExpression('/^CT-\d{6}$/', $items[0]->reference);
    }

    public function testManagedDossiersAreScopedToTheUserAndNewestFirst(): void
    {
        $member = $this->persistUser('member@user-activity-test.local');
        $other = $this->persistUser('other@user-activity-test.local');

        $this->persistDossier('Dossier ancien', new \DateTimeImmutable('2026-01-01'), $member);
        $this->persistDossier('Dossier recent', new \DateTimeImmutable('2026-05-01'), $member);
        $this->persistDossier('Dossier autre', new \DateTimeImmutable('2026-06-01'), $other);
        $this->em->flush();

        $items = $this->repo->managedDossiers((int) $member->getId());

        self::assertCount(2, $items);
        self::assertSame('Dossier recent', $items[0]->label);
        self::assertSame('Dossier ancien', $items[1]->label);
        self::assertMatchesRegularExpression('/^DS-\d{6}$/', $items[0]->reference);
    }

    public function testEmptyActivityReturnsEmptyLists(): void
    {
        $member = $this->persistUser('member@user-activity-test.local');
        $this->em->flush();

        self::assertSame([], $this->repo->assignedLeads((int) $member->getId()));
        self::assertSame([], $this->repo->managedDossiers((int) $member->getId()));
    }

    private function persistUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles([])
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function persistContact(string $firstName, string $lastName, \DateTimeImmutable $createdAt, ?User $assignedTo): Contact
    {
        $contact = (new Contact())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail(strtolower($firstName).'+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt($createdAt)
            ->setAssignedTo($assignedTo);
        $this->em->persist($contact);

        return $contact;
    }

    private function persistDossier(string $name, \DateTimeImmutable $createdAt, User $manager): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt($createdAt)
            ->setManager($manager);
        $this->em->persist($dossier);

        return $dossier;
    }
}
