<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@contactlist-test.local')->execute();
    }

    public function testAdminCanChangeAContactStatus(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());
        $component->changeStatus((int) $contact->getId(), 'in_progress');

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(ContactStatus::InProgress, $reloaded->getStatus());
        self::assertSame('First Last', $reloaded->getStatusChangedBy());
        self::assertNotNull($reloaded->getStatusChangedAt());
    }

    public function testCardsKeepTheirPositionAfterAStatusChange(): void
    {
        $now = new \DateTimeImmutable('now');
        $oldNew = $this->persistContact()->setCreatedAt($now->modify('-3 hours'));
        $freshNew = $this->persistContact()->setCreatedAt($now->modify('-10 minutes'));
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());
        $initialIds = array_map(static fn ($item) => $item->id, $component->getItems());
        self::assertSame([(int) $freshNew->getId(), (int) $oldNew->getId()], $initialIds);

        $component->changeStatus((int) $oldNew->getId(), 'in_progress');

        $afterIds = array_map(static fn ($item) => $item->id, $component->getItems());
        self::assertSame($initialIds, $afterIds, 'Treated card must not jump during the interaction.');
        self::assertSame(ContactStatus::InProgress, $component->getItems()[1]->status, 'The changed card keeps its slot with its new status.');
    }

    public function testFilterLimitsListToStatus(): void
    {
        $this->persistContact();
        $this->persistContact()->setStatus(ContactStatus::InProgress);
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        // Default filter: only untreated submissions.
        self::assertCount(1, $component->getItems());
        self::assertSame(ContactStatus::New, $component->getItems()[0]->status);

        $component->filter('all');
        self::assertCount(2, $component->getItems());

        $component->filter('in_progress');
        self::assertCount(1, $component->getItems());
        self::assertSame(ContactStatus::InProgress, $component->getItems()[0]->status);
        self::assertSame(1, $component->getTotalCount());
    }

    public function testSearchFiltersTheListAndResetsPaging(): void
    {
        $match = $this->persistContact()->setFirstName('Léa')->setLastName('Dupont');
        $this->persistContact()->setFirstName('Marc')->setLastName('Martin');
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        self::assertCount(2, $component->getItems());

        $component->search = 'dupont';
        $component->onSearchUpdated('');

        self::assertCount(1, $component->getItems());
        self::assertSame((int) $match->getId(), $component->getItems()[0]->id);
        self::assertSame(1, $component->getTotalCount());
    }

    public function testSearchMatchesPhoneNumbersHoweverTheyAreTyped(): void
    {
        $match = $this->persistContact()->setPhoneNumber('+33612345678');
        $this->persistContact()->setPhoneNumber('+33799887766');
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        // National format with spaces still finds the E.164-stored number.
        $component->search = '06 12 34 56 78';
        $component->onSearchUpdated('');

        self::assertCount(1, $component->getItems());
        self::assertSame((int) $match->getId(), $component->getItems()[0]->id);
    }

    public function testTreatedCardStaysOnePassAsLeavingThenDisappears(): void
    {
        $contact = $this->persistContact();
        $other = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());
        self::assertCount(2, $component->getItems());

        // The treated card stays visible for the exit animation pass.
        $component->changeStatus((int) $contact->getId(), 'in_progress');
        self::assertSame((int) $contact->getId(), $component->leavingContactId);
        self::assertCount(2, $component->getItems());

        // The next status change drops it from the list entirely.
        $component->changeStatus((int) $other->getId(), 'closed');
        $ids = array_map(static fn ($item) => $item->id, $component->getItems());
        self::assertNotContains((int) $contact->getId(), $ids);
    }

    public function testEmptyNewFilterShowsDedicatedMessage(): void
    {
        $this->persistContact()->setStatus(ContactStatus::Closed);
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        self::assertStringContainsString('Tout est traité', $html);
        self::assertStringContainsString('Aucune nouvelle demande en attente', $html);
    }

    public function testUnknownFilterIsRejected(): void
    {
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        $this->expectException(BadRequestHttpException::class);
        $component->filter('nope');
    }

    public function testUnknownStatusIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        $this->expectException(BadRequestHttpException::class);
        $component->changeStatus((int) $contact->getId(), 'not-a-status');
    }

    public function testNonAdminCannotChangeStatus(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('user@contactlist-test.local', []);
        $this->em->flush();
        $this->loginAs('user@contactlist-test.local');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
    }

    public function testCardRendersStatusDropdownAndReference(): void
    {
        $contact = $this->persistContact()->setReference('CT-082820');
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        // The reference no longer appears as text on the card, only in the
        // detail-page link.
        self::assertStringContainsString('/CT-082820', $html);
        self::assertStringContainsString('data-live-action-param="changeStatus"', $html);
        self::assertStringContainsString('data-live-status-param="closed"', $html);
        self::assertStringContainsString('data-controller="expandable"', $html);
    }

    public function testFreshNewContactShowsRunningCountdown(): void
    {
        $this->persistContact()->setCreatedAt(new \DateTimeImmutable('now'));
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        self::assertStringContainsString('data-controller="countdown"', $html);
        self::assertDoesNotMatchRegularExpression('/data-countdown-target="running"[^>]*hidden/', $html);
        self::assertMatchesRegularExpression('/data-countdown-target="overdue"[^>]*hidden/', $html);
    }

    public function testExpiredNewContactShowsOverdueBadge(): void
    {
        $this->persistContact()->setCreatedAt(new \DateTimeImmutable('-2 hours'));
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        self::assertMatchesRegularExpression('/data-countdown-target="running"[^>]*hidden/', $html);
        self::assertDoesNotMatchRegularExpression('/data-countdown-target="overdue"[^>]*hidden/', $html);
    }

    public function testTreatedContactHasNoCountdown(): void
    {
        $this->persistContact()->setStatus(ContactStatus::InProgress);
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);

        self::assertStringNotContainsString('data-controller="countdown"', $html);
    }

    private function adminPrefix(): string
    {
        return (string) self::getContainer()->getParameter('admin_path_prefix');
    }

    public function testMineScopeShowsOnlyMyAssignedRequests(): void
    {
        $this->seedUser('scope-admin@contactlist-test.local', ['ROLE_ADMIN']);
        $mine = $this->persistContact();
        $other = $this->persistContact();
        $this->em->flush();
        $this->loginAs('scope-admin@contactlist-test.local');
        $me = $this->em->getRepository(User::class)->findOneBy(['email' => 'scope-admin@contactlist-test.local']);
        $mine->setAssignedTo($me);
        $this->em->flush();

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix(), 'statusFilter' => 'all']);
        $component->setLiveResponder(new LiveResponder());
        self::assertCount(2, $component->getItems());

        $component->changeScope('mine');
        $ids = array_map(static fn ($i) => $i->id, $component->getItems());
        self::assertSame([(int) $mine->getId()], $ids, 'Only my assigned requests remain.');
        self::assertSame(1, array_sum($component->getStatusCounts()), 'Rail counts follow the scope.');

        $component->changeScope('all');
        self::assertCount(2, $component->getItems());

        // Unknown scope is ignored.
        $component->changeScope('bogus');
        self::assertSame('all', $component->scope);
    }

    public function testSortByRecallRatingAndBudget(): void
    {
        $now = new \DateTimeImmutable('now');
        $a = $this->persistContact()->setCreatedAt($now->modify('-3 hours'))
            ->setRecallAt($now->modify('+3 days'))->setLeadRating(5)->setProjectBudget(1200);
        $b = $this->persistContact()->setCreatedAt($now->modify('-2 hours'))
            ->setRecallAt($now->modify('+1 day'))->setLeadRating(2)->setProjectBudget(3000);
        $noData = $this->persistContact()->setCreatedAt($now->modify('-1 hour'));
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix(), 'statusFilter' => 'all']);
        $component->setLiveResponder(new LiveResponder());

        $ids = fn () => array_map(static fn ($i) => $i->id, $component->getItems());

        $component->changeSort('recall');
        self::assertSame([(int) $b->getId(), (int) $a->getId(), (int) $noData->getId()], $ids(), 'Closest recall first, none last.');

        $component->changeSort('rating');
        self::assertSame([(int) $a->getId(), (int) $b->getId(), (int) $noData->getId()], $ids(), 'Best rating first, unrated last.');

        $component->changeSort('budget');
        self::assertSame([(int) $b->getId(), (int) $a->getId(), (int) $noData->getId()], $ids(), 'Highest budget first.');

        $component->changeSort('recent');
        self::assertSame([(int) $noData->getId(), (int) $b->getId(), (int) $a->getId()], $ids());

        // Unknown sort is ignored.
        $component->changeSort('bogus');
        self::assertSame('recent', $component->sort);
    }

    public function testFilterRoundTripAlwaysShowsFreshData(): void
    {
        $contact = $this->persistContact();
        $other = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());

        // Treat one card from the default "new" filter.
        $component->changeStatus((int) $contact->getId(), 'in_progress');

        // Switch to "in progress": the treated card must be there.
        $component->filter('in_progress');
        self::assertSame([(int) $contact->getId()], array_map(static fn ($i) => $i->id, $component->getItems()));

        // Back to "new": only the untouched one remains.
        $component->filter('new');
        self::assertSame([(int) $other->getId()], array_map(static fn ($i) => $i->id, $component->getItems()));

        // And "all" sees both.
        $component->filter('all');
        self::assertCount(2, $component->getItems());
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')
            ->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('today 10:00'));

        $this->em->persist($contact);

        return $contact;
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles): void
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
