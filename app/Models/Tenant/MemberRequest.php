<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\ContributionCycleService;
use App\Services\MemberFreezeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_ADD_DEPENDENT = 'add_dependent';

    public const TYPE_REMOVE_DEPENDENT = 'remove_dependent';

    public const TYPE_OWN_ALLOCATION = 'own_allocation';

    public const TYPE_DEPENDENT_ALLOCATION = 'dependent_allocation';

    public const TYPE_REQUEST_INDEPENDENCE = 'request_independence';

    public const TYPE_FREEZE_MEMBERSHIP = 'freeze_membership';

    public const TYPE_UNFREEZE_MEMBERSHIP = 'unfreeze_membership';

    public const TYPE_EXTEND_FREEZE_MEMBERSHIP = 'extend_freeze_membership';

    public const TYPE_WITHDRAW_MEMBERSHIP = 'withdraw_membership';

    public const TYPE_REINSTATE_MEMBERSHIP = 'reinstate_membership';

    public const TYPE_RELEASE_PAYOUT = 'release_payout';

    public const TYPE_OPEN_CYCLE_CONTRIBUTION = 'open_cycle_contribution';

    public const TYPE_VOLUNTARY_CONTRIBUTION = 'voluntary_contribution';

    protected $fillable = [
        'requester_member_id',
        'type',
        'status',
        'payload',
        'admin_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requester_member_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_ADD_DEPENDENT => __('Add dependent'),
            self::TYPE_REMOVE_DEPENDENT => __('Remove dependent'),
            self::TYPE_OWN_ALLOCATION => __('My contribution allocation'),
            self::TYPE_DEPENDENT_ALLOCATION => __('Dependent allocation'),
            self::TYPE_REQUEST_INDEPENDENCE => __('Become independent'),
            self::TYPE_FREEZE_MEMBERSHIP => __('Freeze membership'),
            self::TYPE_UNFREEZE_MEMBERSHIP => __('Unfreeze membership'),
            self::TYPE_EXTEND_FREEZE_MEMBERSHIP => __('Extend freeze'),
            self::TYPE_WITHDRAW_MEMBERSHIP => __('Leave fund'),
            self::TYPE_REINSTATE_MEMBERSHIP => __('Reinstate membership'),
            self::TYPE_RELEASE_PAYOUT => __('Release payout'),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => __('Open-cycle contribution amount'),
            self::TYPE_VOLUNTARY_CONTRIBUTION => __('Contribution top-up'),
            default => $type,
        };
    }

    /**
     * Request types a portal-blocked member may submit from the login surface
     * after credentials are verified (no portal session is created).
     *
     * @return list<string>
     */
    public static function loginSurfaceTypesFor(Member $member): array
    {
        if ($member->status === 'inactive' && $member->frozen_at !== null) {
            return [self::TYPE_UNFREEZE_MEMBERSHIP];
        }

        if ($member->status === 'withdrawn') {
            if ($member->payout_frozen_at !== null) {
                return [
                    self::TYPE_RELEASE_PAYOUT,
                    self::TYPE_REINSTATE_MEMBERSHIP,
                ];
            }

            return [self::TYPE_REINSTATE_MEMBERSHIP];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => __('Pending'),
            self::STATUS_APPROVED => __('Approved'),
            self::STATUS_REJECTED => __('Rejected'),
            self::STATUS_CANCELLED => __('Cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_ADD_DEPENDENT => self::typeLabel(self::TYPE_ADD_DEPENDENT),
            self::TYPE_REMOVE_DEPENDENT => self::typeLabel(self::TYPE_REMOVE_DEPENDENT),
            self::TYPE_OWN_ALLOCATION => self::typeLabel(self::TYPE_OWN_ALLOCATION),
            self::TYPE_DEPENDENT_ALLOCATION => self::typeLabel(self::TYPE_DEPENDENT_ALLOCATION),
            self::TYPE_REQUEST_INDEPENDENCE => self::typeLabel(self::TYPE_REQUEST_INDEPENDENCE),
            self::TYPE_FREEZE_MEMBERSHIP => self::typeLabel(self::TYPE_FREEZE_MEMBERSHIP),
            self::TYPE_UNFREEZE_MEMBERSHIP => self::typeLabel(self::TYPE_UNFREEZE_MEMBERSHIP),
            self::TYPE_EXTEND_FREEZE_MEMBERSHIP => self::typeLabel(self::TYPE_EXTEND_FREEZE_MEMBERSHIP),
            self::TYPE_WITHDRAW_MEMBERSHIP => self::typeLabel(self::TYPE_WITHDRAW_MEMBERSHIP),
            self::TYPE_REINSTATE_MEMBERSHIP => self::typeLabel(self::TYPE_REINSTATE_MEMBERSHIP),
            self::TYPE_RELEASE_PAYOUT => self::typeLabel(self::TYPE_RELEASE_PAYOUT),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => self::typeLabel(self::TYPE_OPEN_CYCLE_CONTRIBUTION),
            self::TYPE_VOLUNTARY_CONTRIBUTION => self::typeLabel(self::TYPE_VOLUNTARY_CONTRIBUTION),
        ];
    }

    /**
     * Human-readable, type-specific detail rows for modals and detail pages (not raw JSON keys).
     *
     * @return list<array{label: string, value: string}>
     */
    public function payloadDetailItems(): array
    {
        $payload = $this->payload ?? [];

        return match ($this->type) {
            self::TYPE_ADD_DEPENDENT => $this->detailItemsAddDependent($payload),
            self::TYPE_REMOVE_DEPENDENT => $this->detailItemsRemoveDependent($payload),
            self::TYPE_OWN_ALLOCATION => $this->detailItemsAmountOnly(
                __('Requested amount'),
                $payload['requested_amount'] ?? null,
            ),
            self::TYPE_DEPENDENT_ALLOCATION => array_values(array_filter([
                ['label' => __('Dependent'), 'value' => $this->formatDependentLabel($payload['dependent_member_id'] ?? null)],
                isset($payload['requested_amount'])
                ? ['label' => __('Requested amount'), 'value' => (string) (int) $payload['requested_amount']]
                : null,
            ])),
            self::TYPE_REQUEST_INDEPENDENCE => [
                ['label' => __('Request'), 'value' => __('Unlink from household parent')],
            ],
            self::TYPE_FREEZE_MEMBERSHIP => $this->detailItemsFreeze($payload),
            self::TYPE_UNFREEZE_MEMBERSHIP => $this->detailItemsReasonFirst(
                __('Resume membership'),
                $payload['reason'] ?? null,
            ),
            self::TYPE_EXTEND_FREEZE_MEMBERSHIP => array_values(array_filter([
                ['label' => __('Additional cycles'), 'value' => (string) (int) ($payload['cycles'] ?? 0)],
                filled(trim((string) ($payload['reason'] ?? '')))
                ? ['label' => __('Reason'), 'value' => trim((string) $payload['reason'])]
                : null,
            ])),
            self::TYPE_WITHDRAW_MEMBERSHIP => $this->detailItemsWithdraw($payload),
            self::TYPE_REINSTATE_MEMBERSHIP => $this->detailItemsReasonFirst(
                __('Request to rejoin'),
                $payload['reason'] ?? null,
            ),
            self::TYPE_RELEASE_PAYOUT => $this->detailItemsReasonFirst(
                __('Request payout release'),
                $payload['reason'] ?? null,
            ),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => $this->detailItemsOpenCycle($payload),
            self::TYPE_VOLUNTARY_CONTRIBUTION => $this->detailItemsVoluntary($payload),
            default => [],
        };
    }

    public function describePayload(): string
    {
        return collect($this->payloadDetailItems())
            ->map(fn(array $item): string => $item['value'])
            ->filter(fn(string $value): bool => $value !== '' && $value !== __('—'))
            ->implode(' · ')
            ?: __('—');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsOpenCycle(array $payload): array
    {
        $amount = isset($payload['amount']) ? number_format((float) $payload['amount'], 2) : __('—');
        $month = (int) ($payload['period_month'] ?? 0);
        $year = (int) ($payload['period_year'] ?? 0);
        $period = ($month > 0 && $year > 0)
            ? app(ContributionCycleService::class)->periodLabel($month, $year)
            : __('—');
        $items = [
            ['label' => __('Period'), 'value' => $period],
            ['label' => __('Member'), 'value' => $this->formatDependentLabel($payload['target_member_id'] ?? $this->requester_member_id)],
            ['label' => __('Requested amount'), 'value' => $amount],
        ];

        if (isset($payload['standing_amount'])) {
            $items[] = [
                'label' => __('Standing monthly allocation'),
                'value' => number_format((float) $payload['standing_amount'], 2),
            ];
        }

        if (filled($payload['note'] ?? null)) {
            $items[] = ['label' => __('Note'), 'value' => (string) $payload['note']];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsVoluntary(array $payload): array
    {
        $amount = isset($payload['amount']) ? number_format((float) $payload['amount'], 2) : __('—');
        $standing = isset($payload['standing_amount']) ? number_format((float) $payload['standing_amount'], 2) : null;
        $extra = (isset($payload['amount']) && isset($payload['standing_amount']))
            ? number_format(round((float) $payload['amount'] - (float) $payload['standing_amount'], 2), 2)
            : null;
        $month = (int) ($payload['period_month'] ?? 0);
        $year = (int) ($payload['period_year'] ?? 0);
        $period = ($month > 0 && $year > 0)
            ? app(ContributionCycleService::class)->periodLabel($month, $year)
            : __('—');

        $items = [
            ['label' => __('Period'), 'value' => $period],
            ['label' => __('Member'), 'value' => $this->formatDependentLabel($payload['target_member_id'] ?? $this->requester_member_id)],
            ['label' => __('Total due this cycle'), 'value' => $amount],
        ];

        if ($extra !== null) {
            $items[] = ['label' => __('Top-up'), 'value' => '+' . $extra];
        }

        if ($standing !== null) {
            $items[] = ['label' => __('Standing monthly allocation'), 'value' => $standing];
        }

        if (filled($payload['note'] ?? null)) {
            $items[] = ['label' => __('Note'), 'value' => (string) $payload['note']];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsFreeze(array $payload): array
    {
        $mode = (string) ($payload['household_mode'] ?? 'self_only');
        $modeLabel = match ($mode) {
            'include_dependents' => __('Freeze me and all dependents'),
            'temp_parent' => __('Elect a temporary funding parent'),
            default => __('Freeze me only'),
        };

        $items = [
            [
                'label' => __('Freeze length'),
                'value' => MemberFreezeService::formatCyclesLabel(
                    MemberFreezeService::normalizeCycles($payload['cycles'] ?? null),
                ),
            ],
            ['label' => __('Household during freeze'), 'value' => $modeLabel],
        ];

        if ($mode === 'temp_parent' && filled($payload['temporary_parent_member_id'] ?? null)) {
            $items[] = [
                'label' => __('Temporary funding parent'),
                'value' => $this->formatDependentLabel($payload['temporary_parent_member_id']),
            ];
        }

        if (filled(trim((string) ($payload['reason'] ?? '')))) {
            $items[] = ['label' => __('Reason'), 'value' => trim((string) $payload['reason'])];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsWithdraw(array $payload): array
    {
        $mode = (string) ($payload['household_mode'] ?? 'self_only');
        $modeLabel = match ($mode) {
            'include_dependents' => __('Withdraw me and all dependents'),
            'permanent_parent' => __('Elect a permanent household parent'),
            default => __('Leave as self only'),
        };

        $items = [
            ['label' => __('Household when leaving'), 'value' => $modeLabel],
        ];

        if ($mode === 'permanent_parent' && filled($payload['permanent_parent_member_id'] ?? null)) {
            $items[] = [
                'label' => __('Permanent household parent'),
                'value' => $this->formatDependentLabel($payload['permanent_parent_member_id']),
            ];
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        $items[] = [
            'label' => __('Reason'),
            'value' => $reason !== '' ? $reason : __('Voluntary leave'),
        ];

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsAddDependent(array $payload): array
    {
        $items = [];

        if (filled($payload['new_email'] ?? null)) {
            $items[] = ['label' => __('New parent email'), 'value' => (string) $payload['new_email']];
        }

        $details = trim((string) ($payload['details'] ?? ''));

        if ($details !== '') {
            $items[] = ['label' => __('Details'), 'value' => $details];
        }

        if (filled($payload['dependent_name'] ?? null)) {
            $items[] = ['label' => __('Dependent name'), 'value' => (string) $payload['dependent_name']];
        }

        return $items !== [] ? $items : [['label' => __('Details'), 'value' => __('—')]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsRemoveDependent(array $payload): array
    {
        $items = [
            ['label' => __('Dependent'), 'value' => $this->formatDependentLabel($payload['dependent_member_id'] ?? null)],
        ];

        if (filled($payload['separated_email'] ?? null)) {
            $items[] = ['label' => __('Separated email'), 'value' => (string) $payload['separated_email']];
        }

        return $items;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsAmountOnly(string $label, mixed $amount): array
    {
        return [
            [
                'label' => $label,
                'value' => $amount !== null ? (string) (int) $amount : __('—'),
            ]
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected function detailItemsReasonFirst(string $fallback, mixed $reason): array
    {
        $text = trim((string) ($reason ?? ''));

        return [
            [
                'label' => __('Reason'),
                'value' => $text !== '' ? $text : $fallback,
            ]
        ];
    }

    protected function formatDependentLabel(mixed $memberId): string
    {
        $id = (int) $memberId;

        if ($id <= 0) {
            return __('—');
        }

        $member = Member::query()->find($id);

        return $member instanceof Member
            ? $member->name . ' (' . $member->member_number . ')'
            : __('Member #:id', ['id' => $id]);
    }
}
