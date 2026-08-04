<?php

declare(strict_types=1);

namespace App\Support;

final class BankStatementBuckets
{
    public const MEMBER_POSTINGS = 'member-postings';

    public const MEMBER_CASH_OUTS = 'member-cash-outs';

    public const MASTER_EXPENSE_DISBURSEMENTS = 'master-expense-disbursements';

    public const MASTER_FEE_DISBURSEMENTS = 'master-fee-disbursements';

    public const MASTER_INVEST_DISBURSEMENTS = 'master-invest-disbursements';

    public const MASTER_INVEST_RETURNS = 'master-invest-returns';

    /** Inbound membership / renewal fee transfers awaiting bank match (like member postings). */
    public const MEMBERSHIP_SUBSCRIPTION_FEES = 'membership-subscription-fees';

    /** Historical cut-off balance placeholders — not bank-match targets. */
    public const IMPORT_CUTOFF_BALANCES = 'import-cutoff-balances';

    /**
     * Synthetic rows that are never paired as imported bank evidence.
     *
     * @var list<string>
     */
    public const MEMBERSHIP_IMPORT_PLACEHOLDERS = [
        self::IMPORT_CUTOFF_BALANCES,
    ];

    /**
     * @var list<string>
     */
    public const OPERATIONAL_CLEARANCE = [
        self::MEMBER_POSTINGS,
        self::MEMBER_CASH_OUTS,
        self::MASTER_EXPENSE_DISBURSEMENTS,
        self::MASTER_FEE_DISBURSEMENTS,
        self::MASTER_INVEST_DISBURSEMENTS,
        self::MASTER_INVEST_RETURNS,
        self::MEMBERSHIP_SUBSCRIPTION_FEES,
    ];

    /**
     * @var list<string>
     */
    public const SYNTHETIC_OPERATIONAL = [
        ...self::MEMBERSHIP_IMPORT_PLACEHOLDERS,
        ...self::OPERATIONAL_CLEARANCE,
    ];
}
