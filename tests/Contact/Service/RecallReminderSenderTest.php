<?php

declare(strict_types=1);

namespace App\Tests\Contact\Service;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactRepository;
use App\Contact\Service\RecallReminderSender;
use App\Shared\Email\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class RecallReminderSenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordingMailer $mailer;
    private RecallReminderSender $sender;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recall-test.local')->execute();

        $this->mailer = new RecordingMailer();
        $container->set(MailerInterface::class, $this->mailer);
        $this->sender = $container->get(RecallReminderSender::class);
    }

    public function testDayReminderGoesToAgencyAndAssignee(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 10:00');
        $assignee = $this->seedAssignee('julien@recall-test.local');
        $this->persistContact($now->modify('+20 hours'))->setAssignedTo($assignee);
        $this->em->flush();

        self::assertSame(1, $this->sender->send($now));

        $email = $this->mailer->sent[0];
        $recipients = array_map(static fn ($a) => $a->getAddress(), $email->getTo());
        self::assertContains(EmailAddress::CONTACT->value, $recipients);
        self::assertContains('julien@recall-test.local', $recipients);
        self::assertStringContainsString('Rappel demain', $email->getSubject());

        // Second run: nothing new.
        self::assertSame(0, $this->sender->send($now->modify('+5 minutes')));
    }

    public function testOnlyTheMostImminentWindowIsSent(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 10:00');
        // Recall in 30 minutes: day AND hour windows both entered, soon not.
        $this->persistContact($now->modify('+30 minutes'));
        $this->em->flush();

        self::assertSame(1, $this->sender->send($now));
        self::assertStringContainsString('dans 1 heure', $this->mailer->sent[0]->getSubject());

        // The wider "day" window was marked too: nothing else until "soon".
        self::assertSame(0, $this->sender->send($now->modify('+1 minute')));
        self::assertSame(1, $this->sender->send($now->modify('+26 minutes')));
        self::assertStringContainsString('dans 5 minutes', $this->mailer->sent[1]->getSubject());
    }

    public function testMovingTheRecallRearmsTheReminders(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 10:00');
        $contact = $this->persistContact($now->modify('+20 hours'));
        $this->em->flush();

        self::assertSame(1, $this->sender->send($now));

        /** @var ContactRepository $repository */
        $repository = self::getContainer()->get(ContactRepository::class);
        $repository->saveRecallAt((int) $contact->getId(), $now->modify('+22 hours'));

        self::assertSame(1, $this->sender->send($now->modify('+1 minute')), 'A moved recall re-arms the day reminder.');
    }

    public function testPastRecallsAreIgnored(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 10:00');
        $this->persistContact($now->modify('-1 hour'));
        $this->em->flush();

        self::assertSame(0, $this->sender->send($now));
    }

    private function persistContact(\DateTimeImmutable $recallAt): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('2026-07-30 09:00'))
            ->setRecallAt($recallAt);
        $this->em->persist($contact);

        return $contact;
    }

    private function seedAssignee(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Julien')->setLastName('Moreau')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }
}

/**
 * @internal
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
    {
        \assert($message instanceof Email);
        // Render-agnostic: TemplatedEmail is only rendered by the real
        // transport layer; the recorder just captures the message.
        $this->sent[] = $message;
    }
}
