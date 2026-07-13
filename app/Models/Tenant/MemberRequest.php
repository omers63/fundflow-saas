<?php

declare(strict_types=1);

namespace App\Models\Tenant;

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
            self::TYPE_WITHDRAW_MEMBERSHIP => __('Withdraw from fund'),
            default => $type,
        };
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
            self::TYPE_WITHDRAW_MEMBERSHIP => trim((string) ($payload['reason'] ?? '')) ?: __('Voluntary withdrawal'),
            default => __('—'),
        };
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
