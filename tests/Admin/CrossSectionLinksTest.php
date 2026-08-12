<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Cross-section links in the BO: a member only gets a clickable link to a
 * section they can actually open. Without the role the information stays
 * readable (it already belongs to the page context) but never leads to a
 * 403 the user cannot act on.
 */
final class CrossSectionLinksTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        // The visit rows carry a CSRF-protected delete form: rendering them
        // outside an HTTP request needs a session in the stack.
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));
        self::getContainer()->get('request_stack')->push($request);

        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class.' v WHERE v.address LIKE :p')->setParameter('p', 'Xlink%')->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.name LIKE :p')->setParameter('p', 'Xlink%')->execute();
        $this->em->createQuery('DELETE FROM '.Contact::class.' c WHERE c.email LIKE :p')->setParameter('p', '%@xlink-test.local')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@xlink-test.local')->execute();
    }

    public function testVisitRowsLinkToTheDossierOnlyWithTheDossiersSection(): void
    {
        $dossier = $this->persistDossier();
        $this->persistVisit($dossier);

        // Visits-only member (the "agent de visite" case): the dossier name
        // stays visible, the link does not.
        $this->loginWithRoles(['ROLE_SECTION_VISITS']);
        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringContainsString('Xlink Dossier', $rendered);
        self::assertStringNotContainsString('/admin/dossiers/'.$dossier->getReference(), $rendered);

        // With the dossiers section, the row links through.
        $this->loginWithRoles(['ROLE_SECTION_VISITS', 'ROLE_SECTION_DOSSIERS']);
        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        self::assertStringContainsString('/admin/dossiers/'.$dossier->getReference(), $rendered);
    }

    public function testDossierOriginLinksToTheLeadOnlyWithTheContactsSection(): void
    {
        $contact = $this->persistContact();
        $dossier = $this->persistDossier();
        $dossier->setSourceContactReference((string) $contact->getReference());
        $this->em->flush();

        // Dossiers-only member: the origin reference is shown, not linked.
        $this->loginWithRoles(['ROLE_SECTION_DOSSIERS']);
        $rendered = (string) $this->renderTwigComponent('Dossier:Notes', [
            'dossierId' => (int) $dossier->getId(),
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'notesOpen' => true,
        ]);
        self::assertStringContainsString((string) $contact->getReference(), $rendered);
        self::assertStringContainsString('data-testid="dossier-origin-ref"', $rendered);
        self::assertStringNotContainsString('data-testid="dossier-origin-link"', $rendered);

        // With the contacts section, it becomes a link to the lead.
        $this->loginWithRoles(['ROLE_SECTION_DOSSIERS', 'ROLE_SECTION_CONTACTS']);
        $rendered = (string) $this->renderTwigComponent('Dossier:Notes', [
            'dossierId' => (int) $dossier->getId(),
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'notesOpen' => true,
        ]);
        self::assertStringContainsString('data-testid="dossier-origin-link"', $rendered);
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('Xlink')
            ->setLastName('Lead')
            ->setEmail('lead@xlink-test.local')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function persistDossier(): Dossier
    {
        $dossier = (new Dossier())
            ->setName('Xlink Dossier')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function persistVisit(Dossier $dossier): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+2 days 10:00'))
            ->setAddress('Xlink 10 rue de Test, Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $email = 'staff@xlink-test.local';
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            $user = (new User())
                ->setEmail($email)
                ->setFirstName('Sam')
                ->setLastName('Xlink')
                ->setCreatedAt(new \DateTimeImmutable())
                ->setProfileComplete(true)
                ->setVerified(true);
            $user->setPassword('irrelevant');
            $this->em->persist($user);
        }
        $user->setRoles($roles);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
