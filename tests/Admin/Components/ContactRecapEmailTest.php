<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\StayDuration;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactRecapEmailTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;
    private RecordingRecapMailer $mailer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recap-test.local')->execute();

        $this->mailer = new RecordingRecapMailer();
        self::getContainer()->set(MailerInterface::class, $this->mailer);
    }

    public function testTwoStepConfirmSendsTheRecapWithProjectAndAdvisor(): void
    {
        $admin = (new User())
            ->setEmail('julien@recap-test.local')
            ->setFirstName('Julien')->setLastName('Moreau')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);

        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane@example.com')
            ->setPhoneNumber('+33612345678')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setOffer('accompagne')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('now'))
            ->setProjectBudget(1800)
            ->setProjectAreas('11e,92')
            ->setProjectStayDuration(StayDuration::Medium)
            ->setProjectFurnishing('furnished,unfurnished')
            ->setProjectPropertyType('t2,loft')
            ->setAssignedTo($admin);
        $this->em->persist($contact);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $component = $this->mountTwigComponent('Admin:ContactRecapEmail', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        self::assertSame('idle', $component->state);

        // Opening the modal sends nothing yet.
        $component->openModal();
        self::assertSame('modal', $component->state);
        self::assertNull($this->mailer->lastMessage);
        self::assertTrue($component->getPaymentAvailable(), 'Package set: the payment link can be offered.');

        $component->togglePayment();
        self::assertTrue($component->includePayment);

        $component->send();
        self::assertSame('sent', $component->state);

        $email = $this->mailer->lastMessage;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('jane@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Votre projet logement à Paris | Relocation in Paris', (string) $email->getSubject());

        // The stub mailer skips Symfony's BodyRenderer: render the Twig
        // template explicitly, like the real transport would.
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('1', $html);
        // Districts render as chips under the map.
        self::assertStringContainsString('>11e</span>', $html);
        self::assertStringContainsString('>Hauts-de-Seine (92)</span>', $html);
        self::assertStringContainsString('Moyen terme', $html);
        self::assertStringContainsString('Accompagné', $html);
        self::assertStringContainsString('Julien Moreau', $html, 'The advisor block names the assignee.');
        self::assertStringContainsString('Arrondissements souhaités', $html);
        self::assertStringContainsString('https://wa.me/33761719439', $html, 'WhatsApp button in the advisor block.');
        self::assertStringContainsString('tel:+33184804344', $html, 'Call button in the advisor block.');
        self::assertStringNotContainsString('en répondant à cet email', $html);
        self::assertStringContainsString('maps.googleapis.com/maps/api/staticmap', $html, 'The highlighted districts map is embedded.');
        self::assertStringContainsString('path=', $html, 'District polygons are drawn on the static map.');
        self::assertStringContainsString('Jane', $html);
        self::assertStringNotContainsString('127.0.0.1', $html, 'No internal data in a client email.');
        self::assertStringContainsString('https://payment.relocation-in-paris.fr/b/00wfZh7hV2RLeeL6oH6kg05', $html, 'The accompagne payment link is embedded.');
        self::assertStringContainsString('Confirmer ma formule', $html);
        self::assertStringContainsString('Et ensuite ?', $html, 'The next-steps block is present.');
        self::assertStringContainsString('https://share.google/c8msBrKphxqVY03er', $html, 'The Google reviews proof is clickable.');
        self::assertStringContainsString('votre projet en un coup d', $html, 'The preheader is embedded.');

        // The send is traced in the follow-up thread with its author.
        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)
            ->listForContact((int) $contact->getId());
        self::assertCount(1, $events);
        self::assertSame('recap_email_payment', $events[0]->kind);
        self::assertSame('Julien Moreau', $events[0]->authorName);
    }

    public function testDepositLinkForConfieAndEnglishLinksFollowTheLanguage(): void
    {
        $admin = (new User())
            ->setEmail('dep@recap-test.local')
            ->setFirstName('Julien')->setLastName('Moreau')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);

        $contact = (new Contact())
            ->setFirstName('john')->setLastName('smith')
            ->setEmail('john@example.com')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setOffer('confie')
            ->setMessage('Hello')->setLang('en')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('now'));
        $this->em->persist($contact);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $component = $this->mountTwigComponent('Admin:ContactRecapEmail', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->openModal();
        self::assertTrue($component->getDepositAvailable(), 'Confié offers the 50% deposit.');

        $component->togglePayment();
        $component->toggleDeposit();
        $component->send();

        $email = $this->mailer->lastMessage;
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        // English prospect + deposit → the English deposit link.
        self::assertStringContainsString('https://payment.relocation-in-paris.fr/b/aFa00j0Tx781b2z3cv6kg03', $html);
        self::assertStringContainsString('50% deposit on', $html);
        self::assertStringContainsString('Your housing project', (string) $email->getSubject());

        // Same prospect, full amount → the English full link.
        $component->toggleDeposit();
        $component->send();
        $email = $this->mailer->lastMessage;
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        self::assertStringContainsString('https://payment.relocation-in-paris.fr/b/4gM5kDgSv4ZTeeL3cv6kg00', (string) $email->getHtmlBody());

        // French prospect, Confié + deposit → the French deposit link.
        $contact->setLang('fr');
        $this->em->flush();
        $component->openModal();
        $component->togglePayment();
        $component->toggleDeposit();
        $component->send();
        $email = $this->mailer->lastMessage;
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('https://payment.relocation-in-paris.fr/b/eVq4gz9q3akd8Ur4gz6kg01', $html);
        self::assertStringContainsString('Acompte de 50 % sur', $html);

        // French prospect, Confié full amount → the French full link.
        $component->openModal();
        $component->togglePayment();
        $component->send();
        $email = $this->mailer->lastMessage;
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        self::assertStringContainsString('https://payment.relocation-in-paris.fr/b/00w5kDcCf4ZT2w36oH6kg04', (string) $email->getHtmlBody());
    }

    public function testCloseModalSendsNothingAndPaymentNeedsAPackage(): void
    {
        $admin = (new User())
            ->setEmail('other@recap-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane2@example.com')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('now'));
        $this->em->persist($contact);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $component = $this->mountTwigComponent('Admin:ContactRecapEmail', ['contactId' => (int) $contact->getId()]);
        $component->openModal();
        self::assertFalse($component->getPaymentAvailable(), 'No package: no payment link offered.');

        // Toggling anyway never arms the payment link without a package.
        $component->togglePayment();
        self::assertFalse($component->includePayment);

        $component->closeModal();
        self::assertSame('idle', $component->state);
        self::assertNull($this->mailer->lastMessage);
    }
}

/**
 * @internal
 */
final class RecordingRecapMailer implements MailerInterface
{
    public ?RawMessage $lastMessage = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->lastMessage = $message;
    }
}
