<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Standard pieces of a French rental application file, requested per
 * tenant from the file module.
 */
enum DossierDocumentType: string
{
    case Identity = 'identity';
    case EmploymentContract = 'employment_contract';
    case Payslips = 'payslips';
    case TaxNotice = 'tax_notice';
    case RentReceipts = 'rent_receipts';
    case ProofOfAddress = 'proof_of_address';
    case EmployerLetter = 'employer_letter';
    case Rib = 'rib';
    case StudentProof = 'student_proof';

    public function labelKey(): string
    {
        return 'admin.dossiers.show.modules.file.type.'.$this->value;
    }
}
