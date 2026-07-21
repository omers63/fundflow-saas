<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\ContributionCycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    public const TYPE_WITHDRAW_MEMBERSHIP = 'withdraw_membership';

    public const TYPE_REINSTATE_MEMBERSHIP = 'reinstate_membership';

    public const TYPE_RELEASE_PAYOUT = 'release_payout';

    public const TYPE_OPEN_CYCLE_CONTRIBUTION = 'open_cycle_contribution';

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
            self::TYPE_WITHDRAW_MEMBERSHIP => __('Leave fund'),
            self::TYPE_REINSTATE_MEMBERSHIP => __('Reinstate membership'),
            self::TYPE_RELEASE_PAYOUT => __('Release payout'),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => __('Open-cycle contribution amount'),
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
            self::TYPE_WITHDRAW_MEMBERSHIP => self::typeLabel(self::TYPE_WITHDRAW_MEMBERSHIP),
            self::TYPE_REINSTATE_MEMBERSHIP => self::typeLabel(self::TYPE_REINSTATE_MEMBERSHIP),
            self::TYPE_RELEASE_PAYOUT => self::typeLabel(self::TYPE_RELEASE_PAYOUT),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => self::typeLabel(self::TYPE_OPEN_CYCLE_CONTRIBUTION),
        ];
    }

    /**
     * @return list<string>
     */
    protected function flattenPayloadLines(array $data, string $prefix = ''): array
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                if ($value === []) {
                    $lines[] = $path.': '.__('(empty)');

                    continue;
                }

                $lines = array_merge($lines, $this->flattenPayloadLines($value, $path));
            } else {
                $lines[] = $path.': '.$this->formatPayloadScalar($value);
            }
        }

        return $lines;
    }

    protected function formatPayloadScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? __('yes') : __('no');
        }

        return trim((string) $value);
    }

    public function payloadAsPlainText(): string
    {
        $payload = $this->payload ?? [];

        if ($payload === []) {
            return __('—');
        }

        return implode("\n", $this->flattenPayloadLines($payload));
    }

    public function describePayload(): string
    {
        $payload = $this->payload ?? [];

        return match ($this->type) {
            self::TYPE_ADD_DEPENDENT => $this->formatAddDependentPayload($payload),
            self::TYPE_REMOVE_DEPENDENT => $this->formatRemoveDependentPayload($payload),
            self::TYPE_OWN_ALLOCATION => isset($payload['requested_amount'])
            ? (string) (int) $payload['requested_amount']
            : __('—'),
            self::TYPE_DEPENDENT_ALLOCATION => $this->formatDependentLabel($payload['dependent_member_id'] ?? null)
            .(isset($payload['requested_amount'])
                ? ' → '.(string) (int) $payload['requested_amount']
                : ''),
            self::TYPE_REQUEST_INDEPENDENCE => __('Unlink from household parent'),
            self::TYPE_FREEZE_MEMBERSHIP => trim((string) ($payload['reason'] ?? '')) ?: __('Pause membership'),
            self::TYPE_UNFREEZE_MEMBERSHIP => trim((string) ($payload['reason'] ?? '')) ?: __('Resume membership'),
            self::TYPE_WITHDRAW_MEMBERSHIP => trim((string) ($payload['reason'] ?? '')) ?: __('Voluntary leave'),
            self::TYPE_REINSTATE_MEMBERSHIP => trim((string) ($payload['reason'] ?? '')) ?: __('Request to rejoin'),
            self::TYPE_RELEASE_PAYOUT => trim((string) ($payload['reason'] ?? '')) ?: __('Request payout release'),
            self::TYPE_OPEN_CYCLE_CONTRIBUTION => $this->formatOpenCycleContributionPayload($payload),
            default => __('—'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function formatOpenCycleContributionPayload(array $payload): string
    {
        $amount = isset($payload['amount']) ? number_format((float) $payload['amount'], 2) : __('—');
        $month = (int) ($payload['period_month'] ?? 0);
        $year = (int) ($payload['period_year'] ?? 0);
        $period = ($month > 0 && $year > 0)
            ? app(ContributionCycleService::class)->periodLabel($month, $year)
            : __('—');
        $target = $this->formatDependentLabel($payload['target_member_id'] ?? $this->requester_member_id);
        $standing = isset($payload['standing_amount'])
            ? number_format((float) $payload['standing_amount'], 2)
            : null;

        $parts = [
            __('Period: :period', ['period' => $period]),
            __('Member: :name', ['name' => $target]),
            __('Requested: :amount', ['amount' => $amount]),
        ];

        if ($standing !== null) {
            $parts[] = __('Standing allocation unchanged: :amount', ['amount' => $standing]);
        }

        if (filled($payload['note'] ?? null)) {
            $parts[] = (string) $payload['note'];
        }

        return implode(' · ', $parts);
    }

    protected function formatDependentLabel(mixed $memberId): string
    {
        $id = (int) $memberId;

        if ($id <= 0) {
            return __('—');
        }

        $member = Member::query()->find($id);

        return $member instanceof Member
            ? $member->name.' ('.$member->member_number.')'
            : __('Member #:id', ['id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function formatRemoveDependentPayload(array $payload): string
    {
        $parts = [$this->formatDependentLabel($payload['dependent_member_id'] ?? null)];

        if (filled($payload['separated_email'] ?? null)) {
            $parts[] = __('Separated email: :email', ['email' => $payload['separated_email']]);
        }

        return Str::limit(implode(' · ', $parts), 120);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function formatAddDependentPayload(array $payload): string
    {
        $parts = [];

        if (filled($payload['new_email'] ?? null)) {
            $parts[] = __('New parent email: :email', ['email' => $payload['new_email']]);
        }

        $details = trim((string) ($payload['details'] ?? ''));

        if ($details !== '') {
            $parts[] = $details;
        }

        if ($parts === []) {
            return __('—');
        }

        return Str::limit(implode(' · ', $parts), 120);
    }
}
