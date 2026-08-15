<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierFileModuleViewFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DossierFileModuleViewFactoryTest extends KernelTestCase
{
    private DossierFileModuleViewFactory $views;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->views = self::getContainer()->get(DossierFileModuleViewFactory::class);
    }

    public function testTenantsOnlyListsTenantRolesWithTheirPieces(): void
    {
        $dossier = $this->buildDossier();

        $tenants = $this->views->tenants($dossier);

        self::assertCount(1, $tenants);
        self::assertSame('Dupont Jean', $tenants[0]['name']);
        self::assertCount(1, $tenants[0]['documents']);
        self::assertSame('requested', $tenants[0]['documents'][0]['status']);
    }

    public function testRecipientsRequireAnEmailWhateverTheRole(): void
    {
        $dossier = $this->buildDossier();

        $names = array_column($this->views->recipients($dossier), 'name');

        self::assertSame(['Jean Dupont', 'Paul Martin'], $names);
    }

    public function testPieceCountsOnlyCountReceivedAndValidatedAsDeposited(): void
    {
        $dossier = $this->buildDossier();
        $dossier->getPersons()->first()->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Validated));

        self::assertSame(['deposited' => 1, 'total' => 2], $this->views->pieceCounts($dossier));
    }

    public function testDepositUrlOnlyExistsOnceAPieceWasRequested(): void
    {
        $dossier = $this->buildDossier();

        $url = $this->views->depositUrl($dossier);
        self::assertNotNull($url);
        self::assertStringContainsString('VIEWF1', $url);

        $empty = (new Dossier())
            ->setName('Vide')
            ->setReference('DS-090502')
            ->setPairingCode('VIEWF2')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson((new DossierPerson())->setRole(DossierPersonRole::TENANT));
        self::assertNull($this->views->depositUrl($empty));
        self::assertFalse($this->views->hasDepositedFiles($empty));
    }

    private function buildDossier(): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@viewfactory.example')
            ->setPrimaryContact(true);
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01')));
        $followUpWithEmail = (new DossierPerson())
            ->setRole(DossierPersonRole::FOLLOW_UP)
            ->setFirstName('Paul')
            ->setLastName('Martin')
            ->setEmail('paul@viewfactory.example');
        $followUpNoEmail = (new DossierPerson())
            ->setRole(DossierPersonRole::FOLLOW_UP)
            ->setFirstName('Sans')
            ->setLastName('Email');

        return (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-090501')
            ->setPairingCode('VIEWF1')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant)
            ->addPerson($followUpWithEmail)
            ->addPerson($followUpNoEmail);
    }
}
