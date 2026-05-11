<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Composition possible of a household for the rental file. The string values
 * are stable identifiers stored in the DB; the human label comes from the
 * translation file (admin.tools.documents.request.typology.<case>).
 */
enum HouseholdTypology: string
{
    // Ordered for the UI radio grid: from the simplest household (a single
    // tenant) to the most complex (two tenants + two guarantors), sorted
    // primarily by tenant count, then by guarantor count. The PDF renderer
    // and DB don't care about order — only the form renders this sequence.
    case ONE_TENANT = 'one_tenant';
    case ONE_TENANT_ONE_GUARANTOR = 'one_tenant_one_guarantor';
    case ONE_TENANT_TWO_GUARANTORS = 'one_tenant_two_guarantors';
    case TWO_TENANTS = 'two_tenants';
    case TWO_TENANTS_ONE_GUARANTOR = 'two_tenants_one_guarantor';
    case TWO_TENANTS_TWO_GUARANTORS = 'two_tenants_two_guarantors';

    /**
     * Translation key for the human-friendly label.
     */
    public function labelKey(): string
    {
        return 'admin.tools.documents.request.typology.'.$this->value;
    }
}
