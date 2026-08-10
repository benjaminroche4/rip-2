<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The /_components/ routes bypass the URL access_control: every contacts
 * component must refuse users without ROLE_SECTION_CONTACTS on its own,
 * both on mount and on the plain re-render path (PreReRender guard).
 */
final class ContactSectionSecurityTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@section-sec.local')->execute();
    }

    public function testContactComponentsRefuseUsersWithoutTheSection(): void
    {
        $this->loginAs($this->seedUser('staff@section-sec.local', ['ROLE_STAFF']));

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:ContactList');
    }

    public function testTheReRenderGuardRefusesADemotedUser(): void
    {
        $contact = $this->persistContact();
        $admin = $this->seedUser('admin@section-sec.local', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);

        // Role revoked mid-session: the next re-render must refuse, even
        // with a valid dehydrated payload (no action involved).
        $this->loginAs($this->seedUser('demoted@section-sec.local', ['ROLE_STAFF']));
        $this->expectException(AccessDeniedException::class);
        $component->denyUnlessContactsSection();
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
