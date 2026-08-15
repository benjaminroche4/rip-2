<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DocumentTypeSelection;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\PersonName;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use PHPUnit\Framework\TestCase;

final class DocumentTypeSelectionTest extends TestCase
{
    public function testItDropsUnknownAndDuplicateCheckedValues(): void
    {
        $types = DocumentTypeSelection::clean(['identity', 'identity', 'not-a-type', 'payslips']);

        self::assertSame([DossierDocumentType::Identity, DossierDocumentType::Payslips], $types);
    }

    public function testItOnlyKeepsTypesNotAlreadyRequestedForTheTenant(): void
    {
        $tenant = new DossierPerson();
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested));

        $types = DocumentTypeSelection::newFor($tenant, ['identity', 'payslips']);

        self::assertSame([DossierDocumentType::Payslips], $types);
    }

    public function testItListsThePiecesStillAwaitingADeposit(): void
    {
        $tenant = new DossierPerson();
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested));
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Refused));
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::TaxNotice)
            ->setStatus(DossierDocumentStatus::Validated));

        $types = DocumentTypeSelection::pendingFor($tenant);

        self::assertSame([DossierDocumentType::Identity, DossierDocumentType::Payslips], $types);
    }

    public function testPersonNameToleratesHalfFilledRows(): void
    {
        $person = (new DossierPerson())->setFirstName('  Jean ')->setLastName(null);

        self::assertSame('Jean', PersonName::firstLast($person));
        self::assertSame('Jean', PersonName::lastFirst($person));
        self::assertSame('', PersonName::firstLast(null));

        $full = (new DossierPerson())->setFirstName('Jean')->setLastName('Dupont');
        self::assertSame('Jean Dupont', PersonName::firstLast($full));
        self::assertSame('Dupont Jean', PersonName::lastFirst($full));
    }
}
