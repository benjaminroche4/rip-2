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

    public function testFirstTreatmentOpensTheQualifyModal(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());

        $component->changeStatus((int) $contact->getId(), 'in_progress');
        self::assertSame((int) $contact->getId(), $component->qualifyContactId, 'First treatment opens the qualification modal.');

        $component->closeQualify();
        $component->changeStatus((int) $contact->getId(), 'closed');
        self::assertNull($component->qualifyContactId, 'Later changes do not reopen it.');
    }

    public function testToRecallOpensTheRecallModalAndSavesTheDate(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@contactlist-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@contactlist-test.local');

        $component = $this->mountTwigComponent('Admin:ContactList', ['adminPrefix' => $this->adminPrefix()]);
        $component->setLiveResponder(new LiveResponder());

        $component->changeStatus((int) $contact->getId(), 'to_recall');
        self::assertSame((int) $contact->getId(), $component->recallContactId, 'To-recall opens the recall modal.');
        self::assertNull($component->qualifyContactId, 'The recall modal shows first.');

        $futureRecall = new \DateTimeImmutable('+2 days 14:30');
        $component->recallAt = $futureRecall->format('Y-m-d\TH:i');
        $component->saveRecall();

        $this->em->clear();
        self::assertSame($futureRecall->format('Y-m-d H:i'), $this->em->find(Contact::class, $contact->getId())->getRecallAt()?->format('Y-m-d H:i'));
        self::assertNull($component->recallContactId);
        // First treatment done via "to recall": the qualification modal chains.
        self::assertSame((int) $contact->getId(), $component->qualifyContactId);

        // A later switch back to "to recall" only shows the recall modal.
        $component->closeQualify();
        $component->changeStatus((int) $contact->getId(), 'to_recall');
        $component->closeRecall();
        self::assertNull($component->qualifyContactId, 'No qualification chaining after the first treatment.');
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

        self::assertStringContainsString('Aucune nouvelle demande à traiter.', $html);
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
