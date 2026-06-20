<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportRequest extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const CATEGORY_GENERAL_INQUIRY = 'general_inquiry';

    public const CATEGORY_CASH_DEPOSIT = 'cash_deposit';

    public const CATEGORY_LOAN_INQUIRY = 'loan_inquiry';

    public const CATEGORY_CONTRIBUTION_QUERY = 'contribution_query';

    public const CATEGORY_BALANCE_QUERY = 'balance_query';

    public const CATEGORY_COMPLAINT = 'complaint';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'member_id',
        'category',
        'subject',
        'message',
        'status',
        'escalated_at',
        'assigned_to_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportRequestReply::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true);
    }

    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }

    public function daysOpen(): int
    {
        return (int) $this->created_at?->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => __('Open'),
            self::STATUS_IN_PROGRESS => __('In progress'),
            self::STATUS_RESOLVED => __('Resolved'),
            self::STATUS_CLOSED => __('Closed'),
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'gray',
            self::STATUS_IN_PROGRESS => 'info',
            self::STATUS_RESOLVED => 'success',
            self::STATUS_CLOSED => 'gray',
            default => 'gray',
        };
    }

    public static function slaColor(int $daysOpen): string
    {
        if ($daysOpen > 7) {
            return 'danger';
        }

        if ($daysOpen >= 3) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_GENERAL_INQUIRY => __('General inquiry'),
            self::CATEGORY_CASH_DEPOSIT => __('Cash deposit request'),
            self::CATEGORY_LOAN_INQUIRY => __('Loan inquiry'),
            self::CATEGORY_CONTRIBUTION_QUERY => __('Contribution query'),
            self::CATEGORY_BALANCE_QUERY => __('Balance / account query'),
            self::CATEGORY_COMPLAINT => __('Complaint'),
            self::CATEGORY_OTHER => __('Other'),
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return self::categoryOptions()[$category] ?? $category;
    }
}
