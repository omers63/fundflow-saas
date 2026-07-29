<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises voluntary top-up contribution requests.
 *
 * Business rules:
 *  - Extra amount (requested − standing) must be a positive multiple of 500.
 *  - Extra amount may not exceed 10,000.
 *  - The same `OpenCycleContributionOverrideService` machinery is reused for
 *    payload normalisation, duplicate checking, and applying the approved request.
 */
final class VoluntaryContributionRequestService
{
    public const int STEP = 500;

    public const int MAX_EXTRA = 10_000;

    public function __construct(
        private readonly OpenCycleContributionOverrideService $overrides,
    ) {}

    /**
     * Normalise and validate a voluntary top-up payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalizePayload(Member $requester, array $payload): array
    {
        $this->overrides->validateRequest($requester, $payload, mustBeCurrentOpenPeriod: true);

        $targetId = (int) ($payload['target_member_id'] ?? $requester->id);
        $target = $targetId > 0 && $targetId !== (int) $requester->id
            ? Member::query()->find($targetId)
            : $requester;

        if (! $target instanceof Member) {
            throw ValidationException::withMessages([
                'target_member_id' => __('Select a valid member.'),
            ]);
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $standing = round((float) $target->monthly_contribution_amount, 2);
        $extra = round($amount - $standing, 2);

        $this->assertValidExtra($extra);

        $this->assertNoPendingVoluntaryRequest($requester, $target);

        return $this->overrides->normalizePayload($requester, $payload);
    }

    /**
     * Apply an approved voluntary top-up request.
     * Delegates entirely to the override service — the Contribution row
     * simply gets a higher `amount_due` for this one cycle.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyApproved(Member $requester, array $payload): void
    {
        $this->overrides->applyApproved($requester, $payload);
    }

    /**
     * Build a human-readable extra-amount label for use in forms.
     *
     * @return array<int|float, string>
     */
    public static function extraAmountOptions(): array
    {
        $options = [];

        for ($extra = self::STEP; $extra <= self::MAX_EXTRA; $extra += self::STEP) {
            $options[$extra] = '+ '.number_format($extra);
        }

        return $options;
    }

    /**
     * @throws ValidationException
     */
    private function assertValidExtra(float $extra): void
    {
        if ($extra <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Requested amount must be greater than your standing monthly allocation.'),
            ]);
        }

        if ($extra > self::MAX_EXTRA) {
            throw ValidationException::withMessages([
                'amount' => __('Voluntary top-up may not exceed :max above your monthly allocation.', [
                    'max' => number_format(self::MAX_EXTRA),
                ]),
            ]);
        }

        if (fmod($extra, self::STEP) > 0.001) {
            throw ValidationException::withMessages([
                'amount' => __('Voluntary top-up must be in multiples of :step.', [
                    'step' => number_format(self::STEP),
                ]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoPendingVoluntaryRequest(Member $requester, Member $target): void
    {
        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

        $exists = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->where('type', MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
            ->get()
            ->contains(function (MemberRequest $request) use ($target, $month, $year): bool {
                $payload = $request->payload ?? [];
                $payloadTargetId = (int) ($payload['target_member_id'] ?? $request->requester_member_id);

                return $payloadTargetId === (int) $target->id
                    && (int) ($payload['period_month'] ?? 0) === $month
                    && (int) ($payload['period_year'] ?? 0) === $year;
            });

        if ($exists) {
            throw ValidationException::withMessages([
                'type' => __('A pending voluntary top-up request already exists for this member and period.'),
            ]);
        }
    }
}
