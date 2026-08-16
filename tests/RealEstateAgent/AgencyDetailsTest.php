<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgencyDetails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgencyDetails behaviour: inline edit (name uniqueness,
 * brand find-or-create) and the guarded deletion that leaves its agents
 * registered as independent.
 */
final class AgencyDetailsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-details-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testSavePersistsIdentityBrandAndContact(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');

        $component = $this->mount((int) $agency->getId());
        $component->startEditing();
        $component->name = 'Foncia Paris 12';
        $component->brandName = 'Foncia';
        $component->address = '5 rue du Faubourg, 75012 Paris';
        $component->phone = '+33144556677';
        $component->email = 'paris12@foncia.fr';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertFalse($component->editing);
        $this->em->clear();
        $saved = $this->em->find(Agency::class, $agency->getId());
        self::assertSame('Foncia Paris 12', $saved->getName());
        // A new brand name creates the brand (find-or-create).
        self::assertSame('Foncia', $saved->getBrand()?->getName());
        self::assertSame('5 rue du Faubourg, 75012 Paris', $saved->getAddress());
        self::assertSame('+33144556677', $saved->getPhone());
        self::assertSame('paris12@foncia.fr', $saved->getEmail());
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->persistAgency('Century 21 Bastille');
        $agency = $this->persistAgency('Foncia Paris 11');

        $component = $this->mount((int) $agency->getId());
        $component->startEditing();
        $component->name = 'century 21 bastille';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertTrue($component->editing);
        self::assertArrayHasKey('name', $component->errors);
        $this->em->clear();
        self::assertSame('Foncia Paris 11', $this->em->find(Agency::class, $agency->getId())->getName());
    }

    public function testKeepingItsOwnNameIsNotADuplicate(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');

        $component = $this->mount((int) $agency->getId());
        $component->startEditing();
        $component->address = '1 rue Neuve, 75011 Paris';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertFalse($component->editing);
        self::assertSame([], $component->errors);
    }

    public function testDeleteLeavesItsAgentsAsIndependent(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setAgency($agency)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        $agencyId = (int) $agency->getId();
        $component = $this->mount($agencyId);
        $component->askDelete();
        $response = $component->deleteAgency($this->em);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $this->em->clear();
        self::assertNull($this->em->find(Agency::class, $agencyId));
        $savedAgent = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertNotNull($savedAgent, 'The agent must survive the agency deletion.');
        self::assertNull($savedAgent->getAgency());
    }

    public function testSectionStaffCannotDelete(): void
    {
        // La suppression est réservée aux admins : le staff de section voit
        // la fiche mais l'appel direct au endpoint est refusé.
        $agency = $this->persistAgency('Century 21 Bastille');
        $staff = (new User())
            ->setEmail('staff@agency-details-test.local')
            ->setFirstName('Staff')->setLastName('Section')
            ->setRoles(['ROLE_SECTION_AGENTS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($staff, 'main', $staff->getRoles()),
        );

        $component = $this->mount((int) $agency->getId());

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $component->askDelete();
    }

    private function mount(int $agencyId): AgencyDetails
    {
        /** @var AgencyDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgencyDetails', [
            'agencyId' => $agencyId,
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function persistAgency(string $name): Agency
    {
        $agency = (new Agency())->setName($name)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@agency-details-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
