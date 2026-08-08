<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:Search behaviour: fields mount from the DossierSearch snapshot,
 * autosave creates the row on demand (dossiers created from scratch), chip
 * toggles persist, and completeness drives the header badge.
 */
final class SearchEditorTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@search-editor-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testMountsValuesFromTheSearchSnapshot(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $component = $this->mountEditor($dossier);

        self::assertSame('2500', $component->budget);
        self::assertSame('11e, 12e', $component->areas);
        self::assertSame('2026-10-01', $component->moveInAt);
        self::assertSame('t2,t3', $component->propertyType);
    }

    public function testSaveCreatesTheSearchRowOnAScratchDossier(): void
    {
        $dossier = $this->persistDossier(withSearch: false);

        $component = $this->mountEditor($dossier);
        $component->budget = ' 1800 ';
        $component->save();

        $this->em->clear();
        $search = $this->em->find(Dossier::class, $dossier->getId())->getSearch();
        self::assertNotNull($search);
        self::assertSame(1800, $search->getBudget());
    }

    public function testEditingTheSearchNeverTouchesTheContact(): void
    {
        // The search is its own table: no contact relation, only scalars.
        $dossier = $this->persistDossier(withSearch: true);

        $component = $this->mountEditor($dossier);
        $component->budget = '999';
        $component->save();

        $this->em->clear();
        self::assertSame(999, $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getBudget());
    }

    public function testTogglePropertyTypePersists(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $component = $this->mountEditor($dossier);
        $component->togglePropertyType('loft');

        $this->em->clear();
        self::assertSame('t2,t3,loft', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getPropertyType());

        $component->togglePropertyType('loft');
        $this->em->clear();
        self::assertSame('t2,t3', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getPropertyType());
    }

    public function testChooseStayDurationTogglesOffOnTheActiveOne(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $component = $this->mountEditor($dossier);
        $component->chooseStayDuration('long');
        $this->em->clear();
        self::assertSame('long', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getStayDuration());

        $component->chooseStayDuration('long');
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getSearch()->getStayDuration());
    }

    public function testCompletenessDrivesTheBadge(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $component = $this->mountEditor($dossier);
        // Fixture misses stayDuration → incomplete.
        self::assertFalse($component->isComplete());

        $component->chooseStayDuration('long');
        self::assertTrue($component->isComplete());
    }

    public function testModulesComponentUnlocksOnceTheSearchIsComplete(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $modules = $this->mountTwigComponent('Dossier:Modules', ['dossierId' => (int) $dossier->getId()]);
        self::assertFalse($modules->isUnlocked(), 'stayDuration missing → still locked');

        // The editor completes the search; the listener re-render on
        // "dossier-search:changed" then reads the fresh completeness.
        $this->mountEditor($dossier)->chooseStayDuration('long');
        self::assertTrue($modules->isUnlocked());
    }

    public function testPetsAndFlatshareToggleAndStayOptional(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        $component->choosePets('yes');
        $component->chooseEarlyMoveIn('yes');
        $component->chooseHouseholdType('couple');
        $this->em->clear();
        $search = $this->em->find(Dossier::class, $dossier->getId())->getSearch();
        self::assertSame('yes', $search->getPets());
        self::assertSame('couple', $search->getHouseholdType());
        self::assertSame('yes', $search->getEarlyMoveIn());

        // Back to "not specified" empties the column.
        $component->chooseHouseholdType('');
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getSearch()->getHouseholdType());

        // Lease types: multi-select with toggle-off.
        $component->chooseLeaseType('mobility');
        $component->chooseLeaseType('alur');
        $this->em->clear();
        self::assertSame('mobility,alur', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getLeaseTypes());
        $component->chooseLeaseType('mobility');
        $this->em->clear();
        self::assertSame('alur', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getLeaseTypes());

        // Toggle-off on the active chip.
        $component->choosePets('yes');
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getSearch()->getPets());

        // Optional: completeness never depends on them.
        $component->chooseStayDuration('long');
        self::assertTrue($component->isComplete());
    }

    public function testHousingCriteriaPersistAndToggle(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        // Min surface rides the main autosave.
        $component->minSurface = ' 45 ';
        $component->save();
        $this->em->clear();
        self::assertSame(45, $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getMinSurface());

        $component->chooseMinBedrooms(2);
        $component->chooseElevator('yes');
        $component->chooseParking('no');
        $component->chooseGroundFloor('no');
        $component->chooseTopFloor('yes');
        $component->chooseBudgetCharges('included');
        $this->em->clear();
        $search = $this->em->find(Dossier::class, $dossier->getId())->getSearch();
        self::assertSame(2, $search->getMinBedrooms());
        self::assertSame('yes', $search->getElevator());
        self::assertSame('no', $search->getParking());
        self::assertSame('no', $search->getGroundFloor());
        self::assertSame('yes', $search->getTopFloor());
        self::assertSame('included', $search->getBudgetCharges());

        // Toggle-off on the active values.
        $component->chooseMinBedrooms(2);
        $component->chooseGroundFloor('no');
        $component->chooseTopFloor('yes');
        $component->chooseBudgetCharges('included');
        $this->em->clear();
        $search = $this->em->find(Dossier::class, $dossier->getId())->getSearch();
        self::assertNull($search->getMinBedrooms());
        self::assertNull($search->getGroundFloor());
        self::assertNull($search->getTopFloor());
        self::assertNull($search->getBudgetCharges());

        // Optional: completeness never depends on them.
        $component->chooseStayDuration('long');
        self::assertTrue($component->isComplete());
    }

    public function testTimelineComponentFollowsTheMoveInDate(): void
    {
        $dossier = $this->persistDossier(withSearch: true);

        $timeline = $this->mountTwigComponent('Dossier:Timeline', ['dossierId' => (int) $dossier->getId()]);
        self::assertSame('2026-10-01', $timeline->getMoveInAt()?->format('Y-m-d'));

        // The editor moves the date; the listener re-render then recomputes.
        $editor = $this->mountEditor($dossier);
        $editor->moveInAt = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $editor->save();

        self::assertSame(
            (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            $timeline->getMoveInAt()?->format('Y-m-d'),
        );
        self::assertNotNull($timeline->getTimeline());
    }

    public function testFieldsStartLockedAndIgnoreMutationsWhileLocked(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountTwigComponent('Dossier:Search', ['dossierId' => (int) $dossier->getId()]);
        $component->setLiveResponder(new LiveResponder());

        self::assertTrue($component->locked, 'Locked by default on every page load.');

        $component->budget = '999';
        $component->save();
        $component->chooseStayDuration('long');
        $this->em->clear();
        $search = $this->em->find(Dossier::class, $dossier->getId())->getSearch();
        self::assertSame(2500, $search->getBudget(), 'Mutations are ignored while locked.');
        self::assertNull($search->getStayDuration());

        $component->toggleLock();
        self::assertFalse($component->locked);
    }

    public function testLeaseMismatchFlagsInconsistentDurations(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        self::assertNull($component->getLeaseMismatch());

        // Mobility lease (10 months max) vs a long-term stay.
        $component->chooseLeaseType('mobility');
        $component->chooseStayDuration('long');
        self::assertSame('admin.dossiers.show.search.leaseMismatch.mobilityLong', $component->getLeaseMismatch());

        // ALUR lease (1 year minimum) vs a short-term stay.
        $component->chooseLeaseType('mobility');
        $component->chooseLeaseType('alur');
        $component->chooseStayDuration('short');
        self::assertSame('admin.dossiers.show.search.leaseMismatch.alurShort', $component->getLeaseMismatch());

        // Consistent again once the duration matches.
        $component->chooseStayDuration('long');
        self::assertNull($component->getLeaseMismatch());
    }

    public function testMaxAffordableBudgetFollowsTenantIncomes(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        // No tenant income known: no warning threshold.
        self::assertNull($component->getMaxAffordableBudget());

        $dossier->getPersons()->first()->setMonthlyIncome(5500);
        $this->em->flush();

        // 5500 / 3, rounded down: the landlord's 3x rule.
        self::assertSame(1833, $component->getMaxAffordableBudget());
    }

    public function testImportantAddressesAddRemoveAndCap(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        $component->addressDraft = '  10 rue de Rivoli, 75004 Paris  ';
        $component->addressTypeDraft = 'work';
        $component->addImportantAddress();

        self::assertSame('', $component->addressDraft, 'Draft cleared after adding.');
        $this->em->clear();
        $rows = $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getImportantAddresses();
        self::assertCount(1, $rows);
        self::assertSame('10 rue de Rivoli, 75004 Paris', $rows[0]['address']);
        self::assertSame('work', $rows[0]['type']);

        // Cap at 3 rows: the 4th is ignored.
        foreach (['school', 'gym', 'other'] as $i => $type) {
            $component->addressDraft = 'Adresse '.$i;
            $component->addressTypeDraft = $type;
            $component->addImportantAddress();
        }
        self::assertCount(3, $component->getImportantAddresses());

        // Blank drafts are ignored.
        $component->addressDraft = '   ';
        $component->addImportantAddress();
        self::assertCount(3, $component->getImportantAddresses());

        $component->removeImportantAddress(0);
        $this->em->clear();
        $rows = $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getImportantAddresses();
        self::assertCount(2, $rows);
        self::assertSame('Adresse 0', $rows[0]['address']);
    }

    public function testImportantAddressStoresCoordinatesForTheMapPin(): void
    {
        $dossier = $this->persistDossier(withSearch: true);
        $component = $this->mountEditor($dossier);

        // Places selection: the coordinates ride along and are persisted.
        $component->addressDraft = '10 rue de Rivoli, 75004 Paris';
        $component->addressTypeDraft = 'work';
        $component->addressLatDraft = '48.8556475';
        $component->addressLngDraft = '2.3608874';
        $component->addImportantAddress();

        self::assertSame('', $component->addressLatDraft, 'Coordinate drafts cleared after adding.');
        self::assertSame('', $component->addressLngDraft);
        $this->em->clear();
        $rows = $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getImportantAddresses();
        self::assertSame(48.855648, $rows[0]['lat']);
        self::assertSame(2.360887, $rows[0]['lng']);

        // Free-form entry (or garbage coordinates): the row saves without
        // lat/lng, the front geocodes it as a fallback.
        $component->addressDraft = 'Quelque part à Paris';
        $component->addressLatDraft = 'abc';
        $component->addressLngDraft = '999';
        $component->addImportantAddress();

        $this->em->clear();
        $rows = $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getImportantAddresses();
        self::assertArrayNotHasKey('lat', $rows[1]);
        self::assertArrayNotHasKey('lng', $rows[1]);
    }

    public function testNonAdminCannotMount(): void
    {
        $user = (new User())
            ->setEmail('plain@search-editor-test.local')
            ->setFirstName('Plain')->setLastName('User')
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Dossier:Search', ['dossierId' => 1]);
    }

    private function persistDossier(bool $withSearch): Dossier
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
        if ($withSearch) {
            $dossier->setSearch((new DossierSearch())
                ->setBudget(2500)
                ->setAreas('11e, 12e')
                ->setMoveInAt(new \DateTimeImmutable('2026-10-01'))
                ->setPropertyType('t2,t3')
                ->setFurnishing('unfurnished,furnished')
                ->setGuarantorType('physical'));
        }
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function mountEditor(Dossier $dossier): object
    {
        $component = $this->mountTwigComponent('Dossier:Search', ['dossierId' => (int) $dossier->getId()]);
        $component->setLiveResponder(new LiveResponder());
        // Fields start locked (anti-missclick); tests act as an unlocked admin.
        $component->toggleLock();

        return $component;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin-'.bin2hex(random_bytes(3)).'@search-editor-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->loginAs($admin);
    }

    private function loginAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
