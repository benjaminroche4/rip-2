<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactStatusControlTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@statusctl-test.local')->execute();
    }

    public function testAdminCanChangeStatusFromDetailPage(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('converted');

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(ContactStatus::Converted, $reloaded->getStatus());
        self::assertSame('First Last', $reloaded->getStatusChangedBy());
    }

    public function testTimerShownOnlyWhileUntreated(): void
    {
        $contact = $this->persistContact()->setCreatedAt(new \DateTimeImmutable('now'));
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-controller="countdown"', $html);

        $contact->setStatus(ContactStatus::InProgress);
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        self::assertStringNotContainsString('data-controller="countdown"', $html);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);

        $this->expectException(BadRequestHttpException::class);
        $component->change('nope');
    }

    public function testNonAdminCannotMount(): void
    {
        $this->seedUser('user@statusctl-test.local', []);
        $this->em->flush();
        $this->loginAs('user@statusctl-test.local');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => 1]);
    }

    public function testCancellingAPlannedRecallClearsTheStepAndReopensTheEditor(): void
    {
        $contact = $this->persistContact();
        $contact->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Recontact)
            ->setRecontactChannel(RecontactChannel::Email)
            ->setRecallAt(new \DateTimeImmutable('+3 days'));
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->dropStep();

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getNextStep(), 'The commitment is dropped, not just edited.');
        self::assertNull($reloaded->getRecallAt());
        self::assertNull($reloaded->getRecontactChannel());
        // The editor reopens so the pipeline never stalls with no next step.
        self::assertTrue($component->editingStep);
        self::assertSame('', $component->pendingStep);
    }

    public function testAnUndatedStepCannotBeCancelled(): void
    {
        $contact = $this->persistContact();
        $contact->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::QuotePreparation);
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->dropStep();

        $this->em->clear();
        // Only visio and recontact expose a Cancel button: a stale DOM
        // posting dropStep on anything else must be a no-op.
        self::assertSame(NextStep::QuotePreparation, $this->em->find(Contact::class, $contact->getId())->getNextStep());
    }

    public function testClearPendingRecallErasesTheStagedDateWithoutTouchingTheStoredOne(): void
    {
        $storedRecall = new \DateTimeImmutable('+3 days 10:00');
        $contact = $this->persistContact();
        $contact->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Recontact)
            ->setRecontactChannel(RecontactChannel::Email)
            ->setRecallAt($storedRecall);
        $this->seedUser('admin@statusctl-test.local', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->loginAs('admin@statusctl-test.local');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        // Stage a draft date (as the datetime-local input would), then clear it.
        $component->recallAt = '2030-01-01T10:00';
        $component->dateMissing = true;
        $component->clearPendingRecall();

        self::assertSame('', $component->recallAt, 'The staged date is gone.');
        self::assertFalse($component->dateMissing, 'The missing-date nag is dismissed with it.');

        // Local clear only: nothing persisted until confirmStep().
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(
            $storedRecall->format('Y-m-d H:i'),
            $reloaded->getRecallAt()?->format('Y-m-d H:i'),
            'The stored recall must survive a draft clear.',
        );
        self::assertSame(NextStep::Recontact, $reloaded->getNextStep());
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
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
