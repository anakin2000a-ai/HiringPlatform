<?php

namespace App\Enums;

/**
 * Lifecycle statuses for applicant_documents.
 * Note: 'expired' is documented in domain docs (02_domain_model.md) but has no
 * implemented lifecycle logic. It is excluded here until expiry checks and
 * transition logic are added.
 */
enum DocumentStatus: string
{
    case Pending   = 'pending';
    case Submitted = 'submitted';
    case Signed    = 'signed';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
}
