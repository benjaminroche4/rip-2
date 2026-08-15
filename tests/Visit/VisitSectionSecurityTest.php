<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierSearch;
use App\Visit\Entity\Visit;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The /_components/ routes bypass the URL access_control: every visits
 * component must refuse users without ROLE_SECTION_VISITS on its own, both
 * on mount and on every mutating LiveAction (a valid dehydrated payload can
 * be replayed after a role revocation).
 */
final class VisitSectionSecurityTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-section-sec.local')->execute();
    }

    public function testVisitComponentsRefuseToMountWithoutTheSection(): void
    {
        $this->loginAs($this->seedUser('staff@visit-section-sec.local', ['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']));
        $visit = $this->persistVisit();

        $mounts = [
            fn () => $this->mountTwigComponent('Visit:VisitDetails', ['visitId' => (int) $visit->getId()]),
            fn () => $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'x']),
            fn () => $this->mountTwigComponent('Visit:VisitArchive', ['adminPrefix' => 'x']),
        ];
        foreach ($mounts as $mount) {
            try {
                $mount();
                self::fail('Mounting a visits component without the section must be refused.');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVisitDetailsMutationsRefuseADemotedUser(): void
    {
        $visit = $this->persistVisit();
        $this->loginAs($this->seedUser('visits@visit-section-sec.local', ['ROLE_STAFF', 'ROLE_SECTION_VISITS']));
        $component = $this->mountTwigComponent('Visit:VisitDetails', ['visitId' => (int) $visit->getId()]);
        $component->toggleLock();

        // Role revoked mid-session: replaying the actions with a valid
        // dehydrated payload must refuse.
        $this->loginAs($this->seedUser('demoted@visit-section-sec.local', ['ROLE_STAFF']));
        $this->assertAllDenied([
            fn () => $component->save(),
            fn () => $component->chooseDuration(30),
            fn () => $component->chooseAgent(0),
            fn () => $component->toggleLock(),
            fn () => $component->chooseType('property_visit'),
            fn () => $component->toggleClientPresent(),
            fn () => $component->pickAssignee(0),
        ]);
    }

    public function testVisitFormMutationsRefuseADemotedUser(): void
    {
        $this->persistVisit();
        $this->loginAs($this->seedUser('visits2@visit-section-sec.local', ['ROLE_STAFF', 'ROLE_SECTION_VISITS']));
        $component = $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'x']);

        $this->loginAs($this->seedUser('demoted2@visit-section-sec.local', ['ROLE_STAFF']));
        $this->assertAllDenied([
            fn () => $component->create($this->em, new AddressGeocoder(new MockHttpClient(), '')),
            fn () => $component->chooseLocation(48.85, 2.35),
            fn () => $component->pickAssignee(1),
            fn () => $component->toggleDetails(),
        ]);
    }

    public function testVisitArchiveLoadMoreRefusesADemotedUser(): void
    {
        $this->loginAs($this->seedUser('visits3@visit-section-sec.local', ['ROLE_STAFF', 'ROLE_SECTION_VISITS']));
        $component = $this->mountTwigComponent('Visit:VisitArchive', ['adminPrefix' => 'x']);

        $this->loginAs($this->seedUser('demoted3@visit-section-sec.local', ['ROLE_STAFF']));
        $this->assertAllDenied([
            fn () => $component->loadMore(),
        ]);
    }

    /**
     * @param list<callable(): mixed> $actions
     */
    private function assertAllDenied(array $actions): void
    {
        foreach ($actions as $action) {
            try {
                $action();
                self::fail('The action must refuse a user without ROLE_SECTION_VISITS.');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function persistVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSearch((new DossierSearch())
                ->setBudget(2000)
                ->setAreas('1,2')
                ->setMoveInAt(new \DateTimeImmutable('+2 months'))
                ->setPropertyType('t2')
                ->setStayDuration('long')
                ->setFurnishing('furnished')
                ->setGuarantorType('physical'));
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+1 day 10:30'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
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
