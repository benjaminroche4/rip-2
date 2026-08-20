<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Admin\Domain\DocumentCategory;

/**
 * Full catalogue of the pieces of a French rental application file,
 * requested per tenant from the file module. Mirrors the taxonomy of the
 * "Outils > Demander des documents" screen: same categories
 * (App\Admin\Domain\DocumentCategory, same labels) and a short description
 * under each piece. Cases are declared in display order inside each
 * category.
 */
enum DossierDocumentType: string
{
    // Identité
    case Identity = 'identity';
    case ResidencePermit = 'residence_permit';

    // Travail
    case EmploymentContract = 'employment_contract';
    case Payslips = 'payslips';
    case EmployerLetter = 'employer_letter';
    case CompanyRegistration = 'company_registration';

    // Logement
    case RentReceipts = 'rent_receipts';
    case ProofOfAddress = 'proof_of_address';
    case HomeInsurance = 'home_insurance';

    // Finance
    case TaxNotice = 'tax_notice';
    case Rib = 'rib';
    case BankStatements = 'bank_statements';

    // Études
    case StudentProof = 'student_proof';
    case ScholarshipProof = 'scholarship_proof';

    // Garantie
    case GuarantorCommitment = 'guarantor_commitment';
    case GuarantorId = 'guarantor_id';
    case GuarantorTaxNotice = 'guarantor_tax_notice';
    case GuarantorPayslips = 'guarantor_payslips';
    case GarantmeCertificate = 'garantme_certificate';

    // Autres
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.dossiers.show.modules.file.type.'.$this->value;
    }

    /** Short hint shown under the piece name in the request modal. */
    public function descriptionKey(): string
    {
        return 'admin.dossiers.show.modules.file.typeDescription.'.$this->value;
    }

    /** Same taxonomy as the "Outils > Demander des documents" catalogue. */
    public function category(): DocumentCategory
    {
        return match ($this) {
            self::Identity, self::ResidencePermit => DocumentCategory::IDENTITY,
            self::EmploymentContract, self::Payslips, self::EmployerLetter, self::CompanyRegistration => DocumentCategory::WORK,
            self::RentReceipts, self::ProofOfAddress, self::HomeInsurance => DocumentCategory::HOUSING,
            self::TaxNotice, self::Rib, self::BankStatements => DocumentCategory::FINANCIAL,
            self::StudentProof, self::ScholarshipProof => DocumentCategory::EDUCATION,
            self::GuarantorCommitment, self::GuarantorId, self::GuarantorTaxNotice, self::GuarantorPayslips, self::GarantmeCertificate => DocumentCategory::GUARANTEE,
            self::Other => DocumentCategory::OTHER,
        };
    }

    /**
     * Whole catalogue grouped by category, in category order then case
     * order. Single source for every screen listing the pieces.
     *
     * @return array<string, list<self>> keyed by DocumentCategory value
     */
    public static function byCategory(): array
    {
        $groups = [];
        foreach (DocumentCategory::cases() as $category) {
            $groups[$category->value] = [];
        }
        foreach (self::cases() as $type) {
            $groups[$type->category()->value][] = $type;
        }

        return array_filter($groups, static fn (array $types): bool => [] !== $types);
    }
}
