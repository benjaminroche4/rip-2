<?php

namespace App\Tests\Contact\Repository;

use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
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

    public function testAdjacentReferencesFollowTheFromContextAfterATreatment(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        $older = $this->persistContact($now->modify('-2 hours'))->setReference('CT-000103');
        $middle = $this->persistContact($now->modify('-1 hour'))->setReference('CT-000102');
        $newer = $this->persistContact($now)->setReference('CT-000101');
        $this->em->flush();

        // The middle lead just got treated: it left "new"...
        $this->repository->updateStatus((int) $middle->getId(), ContactStatus::Converted, null, null);

        // ...but with the "new" context the arrows keep triaging that inbox.
        $adjacent = $this->repository->adjacentReferences((int) $middle->getId(), 'new');
        self::assertSame('CT-000101', $adjacent['newer']);
        self::assertSame('CT-000103', $adjacent['older']);

        // "all" spans every status.
        $adjacent = $this->repository->adjacentReferences((int) $middle->getId(), 'all');
        self::assertSame('CT-000101', $adjacent['newer']);
        self::assertSame('CT-000103', $adjacent['older']);

        // No context: falls back to the contact's own (new) status.
        $adjacent = $this->repository->adjacentReferences((int) $middle->getId());
        self::assertNull($adjacent['newer']);
        self::assertNull($adjacent['older']);
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
        // The reduced 4-status lifecycle: new, in_progress, converted, closed.
        self::assertCount(4, $counts);
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

    /**
     * The reminder cron selects on recallAt alone, with no status clause:
     * that is only safe because a planned date cannot outlive the two
     * statuses where an appointment means something. Locking the invariant
     * here keeps the cron honest if updateStatus() ever changes.
     */
    public function testOnlyOngoingAndConvertedLeadsKeepAPlannedRecall(): void
    {
        $slot = new \DateTimeImmutable('+2 days 14:00');

        foreach ([ContactStatus::InProgress, ContactStatus::Converted] as $keeps) {
            $contact = $this->persistContact(new \DateTimeImmutable('-1 hour'));
            $contact->setNextStep(NextStep::Visio)->setRecallAt($slot);
            $this->em->flush();

            $this->repository->updateStatus((int) $contact->getId(), $keeps);
            // The meeting still stands: the record must keep it, or the
            // reminder, the reassignment sync and the cancellation email
            // all lose their input.
            self::assertSame(NextStep::Visio, $contact->getNextStep(), $keeps->value);
            self::assertNotNull($contact->getRecallAt(), $keeps->value);
        }

        foreach ([ContactStatus::Closed, ContactStatus::New] as $drops) {
            $contact = $this->persistContact(new \DateTimeImmutable('-1 hour'));
            $contact->setNextStep(NextStep::Visio)->setRecallAt($slot);
            $this->em->flush();

            $this->repository->updateStatus((int) $contact->getId(), $drops);
            // No appointment left, so the cron can never pick it up.
            self::assertNull($contact->getNextStep(), $drops->value);
            self::assertNull($contact->getRecallAt(), $drops->value);
            self::assertNotContains($contact, $this->repository->findWithUpcomingRecall(new \DateTimeImmutable()));
        }
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
