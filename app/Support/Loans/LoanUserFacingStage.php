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
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Active = 'active';
    case Repaying = 'repaying';
    case Settled = 'settled';
    case Closed = 'closed';

    public static function memberListStatusLabel(Loan $loan): string
    {
        return match ($loan->status) {
            'pending' => __('Under review'),
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
            self::Applied => __('Applied'),
            self::UnderReview => __('Under review'),
            self::Approved => __('Approved'),
            self::Disbursed => __('Disbursed'),
            self::Active => __('Active'),
            self::Repaying => __('Repaying'),
            self::Settled => __('Settled'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * @return list<array{key: string, label: string, state: string, description: ?string}>
     */
    public static function stepperFor(Loan $loan): array
    {
        if (in_array($loan->status, ['rejected', 'cancelled'], true)) {
            return self::terminalStepper($loan);
        }

        $loan->loadMissing(['installments', 'disbursements']);

        $pending = $loan->status === 'pending';
        $approved = $loan->approved_at !== null;
        $disbursedStarted = (float) $loan->amount_disbursed > 0;
        $fullyDisbursed = $loan->isFullyDisbursed();
        $active = in_array($loan->status, ['active', 'transferred', 'completed', 'early_settled'], true);
        $closed = in_array($loan->status, ['completed', 'early_settled'], true);

        $installmentsTotal = $loan->installments->count();
        $installmentsPaid = $loan->installments->where('status', 'paid')->count();
        $installmentsRemaining = max(0, $installmentsTotal - $installmentsPaid);
        $repaymentStarted = $installmentsPaid > 0;
        $repaymentComplete = $installmentsTotal > 0 && $installmentsPaid >= $installmentsTotal;
        $settled = $loan->settled_at !== null || $closed;

        $steps = [
            [
                'key' => self::Applied->value,
                'label' => self::Applied->label(),
                'gate' => true,
                'description' => null,
            ],
            [
                'key' => self::UnderReview->value,
                'label' => self::UnderReview->label(),
                'gate' => !$pending,
                'description' => null,
            ],
            [
                'key' => self::Approved->value,
                'label' => self::Approved->label(),
                'gate' => $approved && $disbursedStarted,
                'description' => null,
            ],
            [
                'key' => self::Disbursed->value,
                'label' => self::Disbursed->label(),
                'gate' => $fullyDisbursed,
                'description' => self::disbursedDescription($loan, $disbursedStarted, $fullyDisbursed),
            ],
            [
                'key' => self::Active->value,
                'label' => self::Active->label(),
                'gate' => $fullyDisbursed && ($closed || $repaymentStarted),
                'description' => null,
            ],
            [
                'key' => self::Repaying->value,
                'label' => self::Repaying->label(),
                'gate' => $closed || $repaymentComplete,
                'description' => self::repayingDescription($installmentsPaid, $installmentsRemaining, $installmentsTotal),
            ],
            [
                'key' => self::Settled->value,
                'label' => self::Settled->label(),
                'gate' => $settled,
                'description' => null,
            ],
            [
                'key' => self::Closed->value,
                'label' => self::Closed->label(),
                'gate' => $closed,
                'description' => null,
            ],
        ];

        return self::assignStepStates($steps, $closed);
    }

    /**
     * @return list<array{key: string, label: string, state: string, description: ?string}>
     */
    private static function terminalStepper(Loan $loan): array
    {
        $terminalLabel = $loan->status === 'rejected' ? __('Rejected') : __('Cancelled');
        $terminalDescription = $loan->rejection_reason ?? $loan->cancellation_reason;

        $steps = [
            [
                'key' => self::Applied->value,
                'label' => self::Applied->label(),
                'gate' => true,
                'description' => null,
            ],
            [
                'key' => self::UnderReview->value,
                'label' => self::UnderReview->label(),
                'gate' => true,
                'description' => null,
            ],
            [
                'key' => self::Closed->value,
                'label' => $terminalLabel,
                'gate' => false,
                'description' => $terminalDescription,
            ],
        ];

        return self::assignStepStates($steps, false);
    }

    /**
     * @param  list<array{key: string, label: string, gate: bool, description: ?string}>  $steps
     * @return list<array{key: string, label: string, state: string, description: ?string}>
     */
    private static function assignStepStates(array $steps, bool $closed): array
    {
        $currentAssigned = false;
        $result = [];

        foreach ($steps as $step) {
            $complete = (bool) $step['gate'];

            if ($closed && ($step['key'] === self::Closed->value)) {
                $state = 'complete';
            } elseif ($complete) {
                $state = 'complete';
            } elseif (!$currentAssigned) {
                $state = 'current';
                $currentAssigned = true;
            } else {
                $state = 'upcoming';
            }

            $description = $step['description'] ?? null;

            if ($state === 'upcoming') {
                $description = null;
            }

            $result[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'description' => $description,
            ];
        }

        return $result;
    }

    private static function disbursedDescription(Loan $loan, bool $disbursedStarted, bool $fullyDisbursed): ?string
    {
        if (!$disbursedStarted) {
            return null;
        }

        $approved = (float) ($loan->amount_approved ?? $loan->amount_requested ?? 0);
        $disbursed = (float) $loan->amount_disbursed;
        $disbursementCount = $loan->disbursements->count();

        if ($fullyDisbursed && $disbursementCount > 1) {
            return trans_choice(
                ':count disbursements · :amount total|:count disbursements · :amount total',
                $disbursementCount,
                [
                    'count' => $disbursementCount,
                    'amount' => number_format($disbursed, 2),
                ],
            );
        }

        if ($fullyDisbursed) {
            return __(':amount disbursed', ['amount' => number_format($disbursed, 2)]);
        }

        if ($disbursementCount > 1) {
            return __(':disbursed of :approved · :count disbursements', [
                'disbursed' => number_format($disbursed, 2),
                'approved' => number_format($approved, 2),
                'count' => $disbursementCount,
            ]);
        }

        return __(':disbursed of :approved disbursed', [
            'disbursed' => number_format($disbursed, 2),
            'approved' => number_format($approved, 2),
        ]);
    }

    private static function repayingDescription(int $paid, int $remaining, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }

        return __(':paid paid · :remaining remaining', [
            'paid' => $paid,
            'remaining' => $remaining,
        ]);
    }
}
