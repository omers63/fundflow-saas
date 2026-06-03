<?php

declare(strict_types=1);

namespace App\Support;

final class BankStatementBuckets
{
    /**
     * @var list<string>
     */
    public const MEMBERSHIP_IMPORT_PLACEHOLDERS = [
        'membership-subscription-fees',
        'import-cutoff-balances',
    ];

    /**
     * @var list<string>
     */
    public const OPERATIONAL_CLEARANCE = [
        'member-postings',
        'member-cash-outs',
        'master-expense-disbursements',
        'master-fee-disbursements',
        'master-invest-disbursements',
        'master-invest-returns',
    ];

    /**
     * @var list<string>
     */
    public const SYNTHETIC_OPERATIONAL = [
        ...self::MEMBERSHIP_IMPORT_PLACEHOLDERS,
        ...self::OPERATIONAL_CLEARANCE,
    ];

    public const MEMBER_POSTINGS = 'member-postings';

    public const MEMBER_CASH_OUTS = 'member-cash-outs';

    public const MASTER_EXPENSE_DISBURSEMENTS = 'master-expense-disbursements';

    public const MASTER_FEE_DISBURSEMENTS = 'master-fee-disbursements';

    public const MASTER_INVEST_DISBURSEMENTS = 'master-invest-disbursements';

    public const MASTER_INVEST_RETURNS = 'master-invest-returns';
}
