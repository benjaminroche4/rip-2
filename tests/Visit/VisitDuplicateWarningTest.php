<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use App\Visit\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Booking a visit on a property someone already booked: the create form and
 * the detail editor both surface the visits that share the address or the
 * listing link. Informative only, never blocking.
 */
final class VisitDuplicateWarningTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;
    private VisitRepository $visits;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->visits = self::getContainer()->get(VisitRepository::class);
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-duplicate-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testTheCreateFormWarnsWhenTheAddressIsAlreadyBooked(): void
    {
        $existing = $this->persistVisit('12 rue de la Roquette, 75011 Paris');

        $component = $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        // Casse et espaces parasites : même bien malgré tout.
        $component->formValues['address'] = '  12 RUE DE LA ROQUETTE, 75011 PARIS ';

        $matches = $component->getMatchingVisits();

        self::assertCount(1, $matches);
        self::assertSame($existing->getReference(), $matches[0]->reference);
    }

    public function testTheCreateFormWarnsWhenTheListingLinkIsAlreadyBooked(): void
    {
        $existing = $this->persistVisit('4 avenue de la Bourdonnais, 75007 Paris', 'https://www.seloger.com/annonces/12345.htm');

        $component = $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        // Autre adresse saisie, mais la même annonce collée sans le "www."
        // ni le schéma : c'est bien le même logement.
        $component->formValues['address'] = '8 rue Cler, 75007 Paris';
        $component->formValues['listingUrl'] = 'seloger.com/annonces/12345.htm/';

        $matches = $component->getMatchingVisits();

        self::assertCount(1, $matches);
        self::assertSame($existing->getReference(), $matches[0]->reference);
    }

    public function testADifferentPropertyRaisesNoWarning(): void
    {
        $this->persistVisit('12 rue de la Roquette, 75011 Paris', 'https://www.seloger.com/annonces/12345.htm');

        $component = $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $component->formValues['address'] = '8 rue Cler, 75007 Paris';
        $component->formValues['listingUrl'] = 'https://www.pap.fr/annonces/999.htm';

        self::assertSame([], $component->getMatchingVisits());
    }

    public function testATooShortAddressNeverMatches(): void
    {
        $this->persistVisit('12 rue de la Roquette, 75011 Paris');

        $component = $this->mountTwigComponent('Visit:VisitForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $component->formValues['address'] = '12 r';

        self::assertSame([], $component->getMatchingVisits());
    }

    public function testTheEditorWarnsAboutTheOtherVisitsButNeverAboutItself(): void
    {
        $edited = $this->persistVisit('12 rue de la Roquette, 75011 Paris');
        $other = $this->persistVisit('12 rue de la Roquette, 75011 Paris');

        $component = $this->mountTwigComponent('Visit:VisitDetails', [
            'visitId' => (int) $edited->getId(),
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        $component->setLiveResponder(new LiveResponder());

        $matches = $component->getMatchingVisits();

        self::assertCount(1, $matches);
        self::assertSame($other->getReference(), $matches[0]->reference);
    }

    public function testTheBannerListsEachMatchWithALinkToIt(): void
    {
        $existing = $this->persistVisit('12 rue de la Roquette, 75011 Paris');

        $rendered = (string) $this->renderTwigComponent('Visit:DuplicateWarning', [
            'visits' => $this->visits->findMatchingSummaries('12 rue de la Roquette, 75011 Paris', null),
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visit-duplicate-warning"', $rendered);
        self::assertStringContainsString($existing->getReference(), $rendered);
        self::assertStringContainsString('/admin/visites/'.$existing->getId(), $rendered);
    }

    public function testTheBannerIsAbsentWithoutAMatch(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:DuplicateWarning', [
            'visits' => [],
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringNotContainsString('data-testid="visit-duplicate-warning"', $rendered);
    }

    public function testTheRepositoryIgnoresEmptyCriteria(): void
    {
        $this->persistVisit('12 rue de la Roquette, 75011 Paris');

        self::assertSame([], $this->visits->findMatchingSummaries('', null));
        self::assertSame([], $this->visits->findMatchingSummaries('   ', '  '));
    }

    private function persistVisit(string $address, ?string $listingUrl = null): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+2 days 10:30'))
            ->setAddress($address)
            ->setListingUrl($listingUrl)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-duplicate-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
