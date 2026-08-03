<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:PersonsManager behaviour on the detail page: inline add / edit /
 * remove of persons with the household rules (min 1 person, always at least
 * one tenant, max 2 per role), primary tag reassignment and the derived
 * dossier name staying in sync with the tenants.
 */
final class PersonsManagerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@persons-manager-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testAddsAFollowUpPerson(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountManager($dossier);

        $component->startAdd();
        self::assertTrue($component->adding);

        $component->role = DossierPersonRole::FOLLOW_UP->value;
        $component->firstName = 'Marie';
        $component->lastName = 'Durand';
        $component->email = 'marie@example.com';
        $component->language = 'en';
        $component->savePerson();

        self::assertSame([], $component->errors);
        self::assertFalse($component->adding);

        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertCount(2, $fresh->getPersons());
        $added = $fresh->getPersons()->last();
        self::assertSame(DossierPersonRole::FOLLOW_UP, $added->getRole());
        self::assertSame(ContactLanguage::EN, $added->getLanguage());
        self::assertSame(1, $added->getPosition());
        self::assertFalse($added->isPrimaryContact());
    }

    public function testAddingASecondTenantUpdatesTheDerivedName(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountManager($dossier);

        $component->startAdd();
        $component->role = DossierPersonRole::TENANT->value;
        $component->firstName = 'Paul';
        $component->lastName = 'Martin';
        $component->email = 'paul@example.com';
        $component->savePerson();

        self::assertSame([], $component->errors);
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        // Primary tenant (Dupont) leads the derived name.
        self::assertSame('Dupont & Martin', $fresh->getName());
    }

    public function testEditsAPersonInPlace(): void
    {
        $dossier = $this->persistDossier();
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountManager($dossier);

        $component->startEdit($tenant->getId());
        self::assertSame('Jean', $component->firstName);

        $component->lastName = 'Lefebvre';
        $component->phone = '+33711223344';
        $component->savePerson();

        self::assertSame([], $component->errors);
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        $person = $fresh->getPersons()->first();
        self::assertSame('Lefebvre', $person->getLastName());
        self::assertSame('+33711223344', $person->getPhone());
        // Derived name follows the tenant's new last name.
        self::assertSame('Lefebvre', $fresh->getName());
    }

    public function testRemovingThePrimaryTenantReassignsTheTag(): void
    {
        $dossier = $this->persistDossier(withSecondTenant: true);
        [$primary, $second] = $dossier->getPersons()->toArray();
        $component = $this->mountManager($dossier);

        $component->removePerson($primary->getId());

        self::assertSame([], $component->errors);
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertCount(1, $fresh->getPersons());
        $remaining = $fresh->getPersons()->first();
        self::assertSame($second->getId(), $remaining->getId());
        self::assertTrue($remaining->isPrimaryContact());
        self::assertSame(0, $remaining->getPosition());
        self::assertSame('Martin', $fresh->getName());
    }

    public function testCannotRemoveTheLastPerson(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountManager($dossier);

        $component->removePerson($dossier->getPersons()->first()->getId());

        self::assertSame('admin.dossiers.create.persons.min', $component->errors['global'] ?? null);
        $this->em->clear();
        self::assertCount(1, $this->em->find(Dossier::class, $dossier->getId())->getPersons());
    }

    public function testCannotRemoveTheOnlyTenant(): void
    {
        $dossier = $this->persistDossier(withFollowUp: true);
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountManager($dossier);

        $component->removePerson($tenant->getId());

        self::assertSame('admin.dossiers.create.persons.tenantRequired', $component->errors['global'] ?? null);
        $this->em->clear();
        self::assertCount(2, $this->em->find(Dossier::class, $dossier->getId())->getPersons());
    }

    public function testCannotTurnTheOnlyTenantIntoAFollowUp(): void
    {
        $dossier = $this->persistDossier(withFollowUp: true);
        $tenant = $dossier->getPersons()->first();
        $component = $this->mountManager($dossier);

        $component->startEdit($tenant->getId());
        $component->role = DossierPersonRole::FOLLOW_UP->value;
        $component->savePerson();

        self::assertSame('admin.dossiers.create.persons.tenantRequired', $component->errors['role'] ?? null);
        $this->em->clear();
        self::assertSame(DossierPersonRole::TENANT, $this->em->find(Dossier::class, $dossier->getId())->getPersons()->first()->getRole());
    }

    public function testMakePrimaryMovesTheTag(): void
    {
        $dossier = $this->persistDossier(withSecondTenant: true);
        [$primary, $second] = $dossier->getPersons()->toArray();
        $component = $this->mountManager($dossier);

        $component->makePrimary($second->getId());

        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        $persons = $fresh->getPersons()->toArray();
        self::assertFalse($persons[0]->isPrimaryContact());
        self::assertTrue($persons[1]->isPrimaryContact());
        // Name re-derived with the new primary first.
        self::assertSame('Martin & Dupont', $fresh->getName());
    }

    public function testInvalidFieldsBlockSave(): void
    {
        $dossier = $this->persistDossier();
        $component = $this->mountManager($dossier);

        $component->startAdd();
        $component->role = '';
        $component->firstName = 'X';
        $component->email = 'nope';
        $component->savePerson();

        self::assertArrayHasKey('role', $component->errors);
        self::assertArrayHasKey('firstName', $component->errors);
        self::assertArrayHasKey('lastName', $component->errors);
        self::assertArrayHasKey('email', $component->errors);
        $this->em->clear();
        self::assertCount(1, $this->em->find(Dossier::class, $dossier->getId())->getPersons());
    }

    private function persistDossier(bool $withSecondTenant = false, bool $withFollowUp = false): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@example.com')
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-000042')
            ->setPairingCode('ABE78L')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);

        if ($withSecondTenant) {
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Paul')
                ->setLastName('Martin')
                ->setEmail('paul@example.com'));
        }
        if ($withFollowUp) {
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::FOLLOW_UP)
                ->setFirstName('Marie')
                ->setLastName('Durand')
                ->setEmail('marie@example.com'));
        }

        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function mountManager(Dossier $dossier): object
    {
        return $this->mountTwigComponent('Dossier:PersonsManager', ['dossierId' => $dossier->getId()]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@persons-manager-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
