<?php

declare(strict_types=1);

namespace App\Support\Loans;

use App\Models\Tenant\Loan;

/**
 * User-facing loan pipeline stages (UI), mapped from internal statuses.
 */
enum LoanUserFacingStage: string
{
    case Applied = 'applied';
    case Approved = 'approved';
    case AllocateLedger = 'allocate_ledger';
    case BankPayout = 'bank_payout';
    case Repaying = 'repaying';
    case Closed = 'closed';

    public static function memberListStatusLabel(Loan $loan): string
    {
        return match ($loan->status) {
            'pending' => __('Application submitted'),
            'approved' => __('Approved — awaiting disbursement'),
            'partially_disbursed' => __('Partially disbursed'),
            'active' => __('Active — repaying'),
            'completed' => __('Repaid'),
            'early_settled' => __('Repaid early'),
            'rejected' => __('Application rejected'),
            'cancelled' => __('Application cancelled'),
            default => Loan::statusOptions()[$loan->status] ?? $loan->status,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Applied => __('Application'),
            self::Approved => __('Approved'),
            self::AllocateLedger => __('Allocate to ledger'),
            self::BankPayout => __('Send to bank'),
            self::Repaying => __('Active loan'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * @return list<array{key: string, label: string, state: string, description: ?string}>
     */
    public static function stepperFor(Loan $loan): array
    {
        if (in_array($loan->status, ['rejected', 'cancelled'], true)) {
            return [
                [
                    'key' => self::Applied->value,
                    'label' => self::Applied->label(),
                    'state' => 'complete',
                    'description' => $loan->rejection_reason ?? $loan->cancellation_reason,
                ],
                [
                    'key' => self::Closed->value,
                    'label' => $loan->status === 'rejected' ? __('Rejected') : __('Cancelled'),
                    'state' => 'current',
                    'description' => null,
                ],
            ];
        }

        $approved = $loan->approved_at !== null || in_array($loan->status, ['approved', 'partially_disbursed', 'active', 'completed', 'early_settled'], true);
        $allocated = (float) $loan->amount_disbursed > 0 || $loan->status === 'active';
        $fullyAllocated = $loan->isFullyDisbursed();
        $payoutDone = $loan->payout_at !== null || $loan->status === 'active';
        $repaying = $loan->status === 'active';
        $closed = in_array($loan->status, ['completed', 'early_settled'], true);

        $steps = [
            ['key' => self::Applied->value, 'label' => self::Applied->label(), 'gate' => true],
            ['key' => self::Approved->value, 'label' => self::Approved->label(), 'gate' => $approved],
            ['key' => self::AllocateLedger->value, 'label' => self::AllocateLedger->label(), 'gate' => $allocated],
            ['key' => self::BankPayout->value, 'label' => self::BankPayout->label(), 'gate' => $payoutDone && $fullyAllocated],
            ['key' => self::Repaying->value, 'label' => self::Repaying->label(), 'gate' => $repaying || $closed],
            ['key' => self::Closed->value, 'label' => self::Closed->label(), 'gate' => $closed],
        ];

        $currentAssigned = false;
        $result = [];

        foreach ($steps as $step) {
            $complete = (bool) $step['gate'];
            if ($closed && $step['key'] === self::Closed->value) {
                $state = 'complete';
            } elseif ($complete) {
                $state = 'complete';
            } elseif (! $currentAssigned) {
                $state = 'current';
                $currentAssigned = true;
            } else {
                $state = 'upcoming';
            }

            $result[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'description' => null,
            ];
        }

        if (in_array($loan->status, ['approved', 'partially_disbursed'], true) && ! $fullyAllocated) {
            $result[2]['description'] = __(':disbursed of :approved disbursed', [
                'disbursed' => number_format((float) $loan->amount_disbursed, 2),
                'approved' => number_format((float) $loan->amount_approved, 2),
            ]);
        }

        return $result;
    }
}
