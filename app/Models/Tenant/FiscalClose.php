<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Support\FiscalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalClose extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_SNAPSHOT = 'snapshot';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_ROLLED_FORWARD = 'rolled_forward';

    public const STATUS_PURGED = 'purged';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'fiscal_year_label',
        'period_start',
        'period_end',
        'status',
        'readiness_report_json',
        'pool_snapshot_json',
        'member_count',
        'active_loan_count',
        'open_arrears_period_count',
        'export_manifest_json',
        'checksum',
        'closed_by',
        'closed_at',
        'approved_by',
        'approved_at',
        'purge_started_at',
        'purge_completed_at',
        'purge_summary_json',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'readiness_report_json' => 'array',
            'pool_snapshot_json' => 'array',
            'export_manifest_json' => 'array',
            'purge_summary_json' => 'array',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'purge_started_at' => 'datetime',
            'purge_completed_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function memberSnapshots(): HasMany
    {
        return $this->hasMany(FiscalCloseMemberSnapshot::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(FiscalCloseWaiver::class);
    }

    public function isRolledForward(): bool
    {
        return in_array($this->status, [self::STATUS_ROLLED_FORWARD, self::STATUS_PURGED], true);
    }

    public function canRollForward(): bool
    {
        return in_array($this->status, [self::STATUS_SNAPSHOT, self::STATUS_PENDING_APPROVAL], true);
    }

    public function hasExports(): bool
    {
        $files = $this->export_manifest_json['files'] ?? [];

        return is_array($files) && $files !== [];
    }

    public function canPurgeTierA(): bool
    {
        if ($this->status !== self::STATUS_ROLLED_FORWARD) {
            return false;
        }

        if (filled($this->purge_summary_json['tier_a']['completed_at'] ?? null)) {
            return false;
        }

        if (FiscalSettings::requiresExportBeforePurge() && ! $this->hasExports()) {
            return false;
        }

        return true;
    }

    public function canPurgeTierB(): bool
    {
        if (! FiscalSettings::includesTierBPurge()) {
            return false;
        }

        if ($this->status !== self::STATUS_ROLLED_FORWARD) {
            return false;
        }

        if (! filled($this->purge_summary_json['tier_a']['completed_at'] ?? null)) {
            return false;
        }

        return ! filled($this->purge_summary_json['tier_b']['completed_at'] ?? null);
    }

    public function canPurge(): bool
    {
        return $this->canPurgeTierA();
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_PURGED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'fiscal_year_label' => $this->fiscal_year_label,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status,
            'member_count' => $this->member_count,
            'checksum' => $this->checksum,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'export_manifest_json' => $this->export_manifest_json,
            'purge_summary_json' => $this->purge_summary_json,
        ];
    }
}
