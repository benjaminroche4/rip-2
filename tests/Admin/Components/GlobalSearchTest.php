<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Cmd+K palette: results grouped by section, each group gated by its
 * ROLE_SECTION_* role, matching on name / email / reference.
 */
final class GlobalSearchTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class.' c WHERE c.email LIKE :p')->setParameter('p', '%@gsearch-test.local')->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.name LIKE :p')->setParameter('p', 'GSearch%')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@gsearch-test.local')->execute();
    }

    public function testFindsLeadsDossiersAndUsersByName(): void
    {
        $contact = $this->persistContact('Zébulon', 'Rechercheglobale');
        $this->persistDossier('GSearch Rechercheglobale');
        $this->loginWithRoles(['ROLE_ADMIN']);

        $html = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
            'query' => 'Rechercheglobale',
        ]);

        self::assertStringContainsString('data-testid="global-search-group-contacts"', $html);
        self::assertStringContainsString('Zébulon Rechercheglobale', $html);
        self::assertStringContainsString((string) $contact->getReference(), $html);
        self::assertStringContainsString('data-testid="global-search-group-dossiers"', $html);
        self::assertStringContainsString('GSearch Rechercheglobale', $html);
    }

    public function testFindsByReferenceAndEmail(): void
    {
        $contact = $this->persistContact('Marcel', 'Referencetest');
        $this->loginWithRoles(['ROLE_ADMIN']);

        $byRef = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
            'query' => (string) $contact->getReference(),
        ]);
        self::assertStringContainsString('Marcel Referencetest', $byRef);

        $byEmail = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
            'query' => 'marcel@gsearch-test.local',
        ]);
        self::assertStringContainsString('Marcel Referencetest', $byEmail);
    }

    public function testGroupsAreGatedByTheSectionRoles(): void
    {
        $this->persistContact('Gaston', 'Sectiongate');
        $this->persistDossier('GSearch Sectiongate');
        // Dossiers section only: the matching lead must stay invisible.
        $this->loginWithRoles(['ROLE_SECTION_DOSSIERS']);

        $html = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
            'query' => 'Sectiongate',
        ]);

        self::assertStringContainsString('data-testid="global-search-group-dossiers"', $html);
        self::assertStringNotContainsString('data-testid="global-search-group-contacts"', $html);
        self::assertStringNotContainsString('Gaston', $html);
    }

    public function testShortQueryShowsTheHintAndRunsNoSearch(): void
    {
        $this->persistContact('Abel', 'Courtquery');
        $this->loginWithRoles(['ROLE_ADMIN']);

        $html = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
            'query' => 'A',
        ]);

        self::assertStringContainsString('Tapez au moins 2 caractères', $html);
        self::assertStringNotContainsString('Courtquery', $html);
    }

    public function testClosedPaletteStaysHiddenInTheDom(): void
    {
        $this->loginWithRoles(['ROLE_ADMIN']);

        $html = (string) $this->renderTwigComponent('Admin:GlobalSearch', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertMatchesRegularExpression('/hidden[^>]*data-global-search-target="modal"|data-global-search-target="modal"[^>]*hidden/s', $html);
    }

    private function persistContact(string $firstName, string $lastName): Contact
    {
        $contact = (new Contact())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail(strtolower($firstName).'@gsearch-test.local')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function persistDossier(string $name): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Tenant')
            ->setLastName('GSearch')
            ->setEmail('tenant-'.strtolower(substr($name, -6)).'@gsearch-test.local')
            ->setPrimaryContact(true);
        $dossier->addPerson($person);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $user = (new User())
            ->setEmail('staff@gsearch-test.local')
            ->setFirstName('Staff')
            ->setLastName('GSearch')
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $user->setPassword('irrelevant');
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
