<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motion extends Model
{
    public const TYPE_SETTING_CHANGE = 'setting_change';

    public const TYPE_EXPENSE_APPROVAL = 'expense_approval';

    public const TYPE_MEMBER_STATUS = 'member_status';

    public const TYPE_DISTRIBUTION_RUN = 'distribution_run';

    public const TYPE_GENERIC = 'generic';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'meeting_id',
        'title',
        'body',
        'type',
        'status',
        'payload',
        'amount_threshold',
        'yes_count',
        'no_count',
        'abstain_count',
        'eligible_voters',
        'quorum_percent',
        'quorum_met',
        'voting_opens_at',
        'voting_closes_at',
        'approved_at',
        'rejected_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount_threshold' => 'decimal:2',
            'quorum_percent' => 'decimal:2',
            'quorum_met' => 'boolean',
            'voting_opens_at' => 'datetime',
            'voting_closes_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MotionAttachment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpenForVoting(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }

        $now = now();

        if ($this->voting_opens_at !== null && $now->lt($this->voting_opens_at)) {
            return false;
        }

        if ($this->voting_closes_at !== null && $now->gt($this->voting_closes_at)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_SETTING_CHANGE => __('Setting change'),
            self::TYPE_EXPENSE_APPROVAL => __('Expense approval'),
            self::TYPE_MEMBER_STATUS => __('Member status'),
            self::TYPE_DISTRIBUTION_RUN => __('Distribution run'),
            self::TYPE_GENERIC => __('Generic'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => __('Draft'),
            self::STATUS_OPEN => __('Open for voting'),
            self::STATUS_APPROVED => __('Approved'),
            self::STATUS_REJECTED => __('Rejected'),
            self::STATUS_CANCELLED => __('Cancelled'),
        ];
    }
}
