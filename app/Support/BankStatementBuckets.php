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
}
