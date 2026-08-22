<?php

declare(strict_types=1);

namespace App\Tests\Contact\Service;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Entity\Contact;
use App\Contact\Service\RecontactNoticeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * L'email "confirmation de la prochaine étape" au prospect part de
 * l'adresse du closer assigné quand elle vit sur le domaine de l'agence,
 * avec les mêmes replis que l'invitation visio (From contact@ + Reply-To
 * closer hors domaine, From contact@ seul sans closer).
 */
final class RecontactNoticeMailerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recontact-mailer-test.local')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', 'rn-closer-%@relocation-in-paris.fr')->execute();
    }

    public function testTheNoticeLeavesFromTheClosersAddress(): void
    {
        $closer = $this->persistUser('rn-closer-'.bin2hex(random_bytes(4)).'@relocation-in-paris.fr', 'Sarah', 'Martin');
        $contact = $this->persistContact($closer);

        self::getContainer()->get(RecontactNoticeMailer::class)->send($contact);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($closer->getEmail(), $email->getFrom()[0]->getAddress());
        self::assertSame('Sarah Martin', $email->getFrom()[0]->getName());
        self::assertSame([], $email->getReplyTo());
        // Le sujet nomme le closer : "Sarah vous rappelle ...".
        self::assertStringStartsWith('Sarah vous rappelle', (string) $email->getSubject());
    }

    public function testAnOffDomainCloserFallsBackToTheCentralAddressWithReplyTo(): void
    {
        $closer = $this->persistUser('sarah@recontact-mailer-test.local', 'Sarah', 'Martin');
        $contact = $this->persistContact($closer);

        self::getContainer()->get(RecontactNoticeMailer::class)->send($contact);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('contact@relocation-in-paris.fr', $email->getFrom()[0]->getAddress());
        self::assertSame($closer->getEmail(), $email->getReplyTo()[0]->getAddress());
    }

    public function testWithoutACloserTheCentralAddressStandsAlone(): void
    {
        $contact = $this->persistContact(null);

        self::getContainer()->get(RecontactNoticeMailer::class)->send($contact);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('contact@relocation-in-paris.fr', $email->getFrom()[0]->getAddress());
        self::assertSame([], $email->getReplyTo());
        self::assertStringStartsWith('Notre équipe vous rappelle', (string) $email->getSubject());
    }

    private function persistContact(?User $assignee): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Recontact)
            ->setRecontactChannel(RecontactChannel::Phone)
            ->setRecallAt(new \DateTimeImmutable('+2 days 10:00'))
            ->setCreatedAt(new \DateTimeImmutable('-1 day'))
            ->setAssignedTo($assignee);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function persistUser(string $email, string $firstName, string $lastName): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)->setLastName($lastName)
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
