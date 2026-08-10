<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:ContactHeading: the live page heading of the contact detail. Reads
 * the fresh name on every render so the "contacts:changed" re-render picks
 * up identity edits without a reload.
 */
final class ContactHeadingTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@contact-heading-test.local')->execute();
    }

    public function testRendersTheFreshNameOnEveryRender(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $html = (string) $this->renderTwigComponent('Admin:ContactHeading', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('Jane Doe', $html);

        // Identity edited elsewhere (ContactDetails): the next render, which
        // the contacts:changed listener triggers, shows the new name.
        $contact->setFirstName('marc')->setLastName('durand');
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:ContactHeading', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('Marc Durand', $html);
    }

    public function testNonContactsStaffCannotMount(): void
    {
        $contact = $this->persistContact();
        $user = (new User())
            ->setEmail('plain@contact-heading-test.local')
            ->setFirstName('Plain')->setLastName('User')
            ->setRoles([])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactHeading', ['contactId' => (int) $contact->getId()]);
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')
            ->setLastName('doe')
            ->setEmail('jane@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')
            ->setLang('fr')
            ->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@contact-heading-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($admin);
        $this->em->flush();
        $this->loginAs($admin);
    }

    private function loginAs(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
