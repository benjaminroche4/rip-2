<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactsNavBadgeTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@navbadge-test.local')->execute();
    }

    public function testRendersZeroWhenNoContact(): void
    {
        $this->loginAsAdmin();

        $html = preg_replace('/\s+/', '', (string) $this->renderTwigComponent('Admin:ContactsNavBadge'));

        self::assertStringContainsString('>0<', $html);
    }

    public function testCountsOnlyUntreatedContacts(): void
    {
        $this->persistContact(new \DateTimeImmutable('today'));
        $this->persistContact(new \DateTimeImmutable('-2 days'));
        $this->persistContact(new \DateTimeImmutable('-30 days'), ContactStatus::Closed);
        $this->loginAsAdmin();

        $html = preg_replace('/\s+/', '', (string) $this->renderTwigComponent('Admin:ContactsNavBadge'));

        self::assertStringContainsString('>2<', $html);
    }

    public function testNonAdminCannotMountTheBadge(): void
    {
        $this->loginAsAdmin(roles: []);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactsNavBadge');
    }

    /**
     * @param list<string> $roles
     */
    private function loginAsAdmin(array $roles = ['ROLE_ADMIN']): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@navbadge-test.local')
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }

    private function persistContact(\DateTimeImmutable $createdAt, ContactStatus $status = ContactStatus::New): void
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
            ->setCreatedAt($createdAt)
            ->setStatus($status);

        $this->em->persist($contact);
        $this->em->flush();
    }
}
