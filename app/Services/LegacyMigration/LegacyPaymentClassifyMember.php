<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Member;

/**
 * Member context for payment classification — from the database or a members CSV preview.
 */
final readonly class LegacyPaymentClassifyMember
{
    public function __construct(
        public string $memberNumber,
        public string $name,
        public string $email,
        public float $monthlyContribution,
        public ?Member $databaseMember = null,
    ) {}

    public static function fromDatabase(Member $member): self
    {
        return new self(
            memberNumber: (string) $member->member_number,
            name: (string) $member->name,
            email: (string) $member->email,
            monthlyContribution: (float) $member->monthly_contribution_amount,
            databaseMember: $member,
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    public static function fromCsvRow(array $row): self
    {
        $monthly = trim((string) ($row['monthly_contribution_amount'] ?? ''));

        return new self(
            memberNumber: trim((string) ($row['member_number'] ?? '')),
            name: trim((string) ($row['name'] ?? $row['member_name'] ?? '')),
            email: strtolower(trim((string) ($row['email'] ?? ''))),
            monthlyContribution: is_numeric($monthly) ? (float) $monthly : 500.0,
        );
    }
}
