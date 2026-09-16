<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tenant admin capability names for disbursement batches.
 * Tenant panel uses is_admin today; these keys match the Phase A plan for Shield later.
 */
final class DisbursementBatchPermissions
{
    public const VIEW = 'disbursement_batches.view';

    public const CREATE = 'disbursement_batches.create';

    public const APPROVE = 'disbursement_batches.approve';
}
