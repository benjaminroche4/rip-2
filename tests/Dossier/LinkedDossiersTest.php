<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Repository\DossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DossierRepository::findByPersonEmail(): the read model behind the
 * "linked dossiers" banner of a lead page. One DTO per dossier carrying
 * the email on any of its persons, case-insensitive, converted-from-this
 * -lead flagged and listed first.
 */
final class LinkedDossiersTest extends KernelTestCase
{
    private const EMAIL = 'linked-dossiers-test@example.com';

    private EntityManagerInterface $em;
    private DossierRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(DossierRepository::class);

        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.reference IN (:refs)')
            ->setParameter('refs', ['DS-771001', 'DS-771002', 'DS-771003'])
            ->execute();
    }

    public function testItReturnsOneDtoPerDossierCarryingTheEmailCaseInsensitively(): void
    {
        $this->persistDossier('DS-771001', 'Relocation Martin', DossierPersonRole::TENANT, self::EMAIL, createdAt: '-2 days');
        $followed = $this->persistDossier('DS-771002', 'Relocation Durand', DossierPersonRole::FOLLOW_UP, 'Linked-Dossiers-TEST@example.com', createdAt: '-1 day');
        $followed->setClosedAt(new \DateTimeImmutable());
        // Noise: a dossier with another email must stay out.
        $this->persistDossier('DS-771003', 'Relocation Petit', DossierPersonRole::TENANT, 'other@example.com');
        $this->em->flush();

        $summaries = $this->repository->findByPersonEmail('LINKED-dossiers-test@EXAMPLE.com');

        self::assertCount(2, $summaries);
        $byReference = array_combine(array_column($summaries, 'reference'), $summaries);
        self::assertArrayHasKey('DS-771001', $byReference);
        self::assertArrayHasKey('DS-771002', $byReference);

        self::assertSame('Relocation Martin', $byReference['DS-771001']->name);
        self::assertSame(DossierPersonRole::TENANT, $byReference['DS-771001']->personRole);
        self::assertFalse($byReference['DS-771001']->closed);
        self::assertFalse($byReference['DS-771001']->fromThisContact);

        self::assertSame('Relocation Durand', $byReference['DS-771002']->name);
        self::assertSame(DossierPersonRole::FOLLOW_UP, $byReference['DS-771002']->personRole);
        self::assertTrue($byReference['DS-771002']->closed);
    }

    public function testItFlagsAndFrontsTheDossierConvertedFromTheContact(): void
    {
        // Newest first would put DS-771002 ahead; the converted one must
        // still lead the list.
        $converted = $this->persistDossier('DS-771001', 'Relocation Martin', DossierPersonRole::TENANT, self::EMAIL, createdAt: '-2 days');
        $converted->setSourceContactReference('CT-909090');
        $this->persistDossier('DS-771002', 'Relocation Durand', DossierPersonRole::FOLLOW_UP, self::EMAIL, createdAt: '-1 day');
        $this->em->flush();

        $summaries = $this->repository->findByPersonEmail(self::EMAIL, 'CT-909090');

        self::assertCount(2, $summaries);
        self::assertSame('DS-771001', $summaries[0]->reference);
        self::assertTrue($summaries[0]->fromThisContact);
        self::assertFalse($summaries[1]->fromThisContact);
    }

    public function testItReturnsNothingForAnUnknownOrBlankEmail(): void
    {
        $this->persistDossier('DS-771001', 'Relocation Martin', DossierPersonRole::TENANT, self::EMAIL);
        $this->em->flush();

        self::assertSame([], $this->repository->findByPersonEmail('absent@example.com'));
        self::assertSame([], $this->repository->findByPersonEmail('  '));
    }

    private function persistDossier(
        string $reference,
        string $name,
        DossierPersonRole $role,
        string $email,
        string $createdAt = 'now',
    ): Dossier {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference($reference)
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
        $person = (new DossierPerson())
            ->setRole($role)
            ->setFirstName('Test')
            ->setLastName('Person')
            ->setEmail($email)
            ->setPrimaryContact(DossierPersonRole::TENANT === $role);
        $dossier->addPerson($person);
        $this->em->persist($dossier);

        return $dossier;
    }
}
