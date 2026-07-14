<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Support\ContributionCollectionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open-cycle contribution due override (replace amount_due for one period only).
 * Does not change members.monthly_contribution_amount.
 */
final class OpenCycleContributionOverrideService
{
    public function __construct(
        private readonly ContributionCycleService $cycles,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{target: Member, amount: float, period_month: int, period_year: int, contribution: ?Contribution, standing_amount: float}
     *
     * @throws ValidationException
     */
    public function validateRequest(Member $requester, array $payload, bool $mustBeCurrentOpenPeriod = true): array
    {
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();

        $periodMonth = (int) ($payload['period_month'] ?? $openMonth);
        $periodYear = (int) ($payload['period_year'] ?? $openYear);

        if ($mustBeCurrentOpenPeriod && ($periodMonth !== $openMonth || $periodYear !== $openYear)) {
            throw ValidationException::withMessages([
                'period' => __('Out-of-tier contribution requests are only allowed for the open collection cycle (:period).', [
                    'period' => $this->cycles->periodLabel($openMonth, $openYear),
                ]),
            ]);
        }

        if ($periodMonth < 1 || $periodMonth > 12 || $periodYear < 2000) {
            throw ValidationException::withMessages([
                'period' => __('Invalid contribution period.'),
            ]);
        }

        $target = $this->resolveTargetMember($requester, $payload);

        if ($target->status !== 'active') {
            throw ValidationException::withMessages([
                'target' => __('Only active members can request an open-cycle contribution amount.'),
            ]);
        }

        if (! $this->cycles->memberIsLiableForContributionPeriod($target, $periodMonth, $periodYear)) {
            throw ValidationException::withMessages([
                'target' => __('This member is not liable for contributions in that cycle.'),
            ]);
        }

        $amount = $this->normalizedAmount($payload['amount'] ?? null);
        $standing = (float) $target->monthly_contribution_amount;

        if ($amount <= $standing) {
            throw ValidationException::withMessages([
                'amount' => __('Requested amount must be greater than the current monthly allocation (:amount).', [
                    'amount' => number_format($standing, 2),
                ]),
            ]);
        }

        $contribution = Contribution::findForMemberPeriod($target->id, $periodMonth, $periodYear);

        if ($contribution !== null) {
            $this->assertContributionAcceptsOverride($contribution, $amount);
        } elseif (! $mustBeCurrentOpenPeriod) {
            [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();

            if ($periodMonth !== $openMonth || $periodYear !== $openYear) {
                throw ValidationException::withMessages([
                    'period' => __('This request can no longer be applied because the open cycle has changed and no pending contribution exists for the requested period.'),
                ]);
            }
        }

        if ($mustBeCurrentOpenPeriod) {
            $this->assertNoPendingDuplicate($requester, $target, $periodMonth, $periodYear);
        }

        return [
            'target' => $target,
            'amount' => $amount,
            'period_month' => $periodMonth,
            'period_year' => $periodYear,
            'contribution' => $contribution,
            'standing_amount' => $standing,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(Member $requester, array $payload): array
    {
        $context = $this->validateRequest($requester, $payload, mustBeCurrentOpenPeriod: true);

        return [
            'amount' => $context['amount'],
            'period_month' => $context['period_month'],
            'period_year' => $context['period_year'],
            'target_member_id' => $context['target']->id,
            'for_self' => (int) $context['target']->id === (int) $requester->id,
            'standing_amount' => $context['standing_amount'],
            'previous_amount_due' => $context['contribution'] !== null
                ? (float) ($context['contribution']->amount_due ?? $context['contribution']->amount)
                : $context['standing_amount'],
            'note' => filled($payload['note'] ?? null) ? trim((string) $payload['note']) : null,
        ];
    }

    /**
     * Apply an approved request: replace period amount / amount_due only.
     *
     * @throws ValidationException
     */
    public function applyApproved(Member $requester, array $payload): Contribution
    {
        $context = $this->validateRequest($requester, $payload, mustBeCurrentOpenPeriod: false);

        return DB::transaction(function () use ($context): Contribution {
            $target = $context['target']->fresh() ?? $context['target'];
            $amount = $context['amount'];
            $month = $context['period_month'];
            $year = $context['period_year'];
            $standingBefore = (float) $target->monthly_contribution_amount;

            $contribution = Contribution::findForMemberPeriod($target->id, $month, $year, withTrashed: true);

            if ($contribution?->trashed()) {
                $contribution->forceDelete();
                $contribution = null;
            }

            if ($contribution === null) {
                $contribution = Contribution::create([
                    'member_id' => $target->id,
                    'period' => Contribution::periodDate($month, $year),
                    'amount' => $amount,
                    'amount_due' => $amount,
                    'amount_collected' => 0,
                    'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                    'status' => 'pending',
                    'collection_status' => ContributionCollectionStatus::PENDING,
                    'cycle_open_cash_balance' => $target->getCashBalance(),
                    'notes' => __('Open-cycle contribution amount approved by administration.'),
                ]);
            } else {
                $this->assertContributionAcceptsOverride($contribution, $amount);

                $contribution->update([
                    'amount' => $amount,
                    'amount_due' => $amount,
                    'notes' => trim(implode(' ', array_filter([
                        (string) ($contribution->notes ?? ''),
                        __('Open-cycle contribution amount approved by administration.'),
                    ]))),
                ]);
            }

            $target->refresh();

            if (abs((float) $target->monthly_contribution_amount - $standingBefore) > 0.0001) {
                $target->update(['monthly_contribution_amount' => $standingBefore]);
            }

            return $contribution->fresh() ?? $contribution;
        });
    }

    /**
     * @throws ValidationException
     */
    private function resolveTargetMember(Member $requester, array $payload): Member
    {
        $targetId = (int) ($payload['target_member_id'] ?? $requester->id);

        if ($targetId <= 0 || $targetId === (int) $requester->id) {
            return $requester;
        }

        $target = Member::query()->find($targetId);

        if ($target === null) {
            throw ValidationException::withMessages([
                'target_member_id' => __('Select a valid dependent.'),
            ]);
        }

        if ((int) $target->parent_member_id !== (int) $requester->id) {
            throw ValidationException::withMessages([
                'target_member_id' => __('You may only request an open-cycle amount for yourself or your dependents.'),
            ]);
        }

        return $target;
    }

    /**
     * @throws ValidationException
     */
    private function assertContributionAcceptsOverride(Contribution $contribution, float $amount): void
    {
        if ($contribution->status === 'posted'
            || $contribution->collection_status === ContributionCollectionStatus::COLLECTED) {
            throw ValidationException::withMessages([
                'amount' => __('This cycle’s contribution is already collected and cannot be changed.'),
            ]);
        }

        $collected = (float) ($contribution->amount_collected ?? 0);

        if ($amount + 0.0001 < $collected) {
            throw ValidationException::withMessages([
                'amount' => __('Requested amount must be at least the amount already collected (:amount).', [
                    'amount' => number_format($collected, 2),
                ]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoPendingDuplicate(
        Member $requester,
        Member $target,
        int $periodMonth,
        int $periodYear,
    ): void {
        $exists = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->where('type', MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION)
            ->get()
            ->contains(function (MemberRequest $request) use ($target, $periodMonth, $periodYear): bool {
                $payload = $request->payload ?? [];
                $payloadTargetId = (int) ($payload['target_member_id'] ?? $request->requester_member_id);

                return $payloadTargetId === (int) $target->id
                    && (int) ($payload['period_month'] ?? 0) === $periodMonth
                    && (int) ($payload['period_year'] ?? 0) === $periodYear;
            });

        if ($exists) {
            throw ValidationException::withMessages([
                'type' => __('A pending open-cycle contribution request already exists for this member and period.'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function normalizedAmount(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'amount' => __('Enter a valid contribution amount.'),
            ]);
        }

        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Enter a valid contribution amount.'),
            ]);
        }

        return $value;
    }
}
