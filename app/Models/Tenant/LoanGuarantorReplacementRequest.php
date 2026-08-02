<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanGuarantorReplacementRequest extends Model
{
    public const STATUS_PENDING_ADMIN = 'pending_admin';

    public const STATUS_PENDING_GUARANTOR = 'pending_guarantor';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const ROLE_BORROWER = 'borrower';

    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'loan_id',
        'outgoing_guarantor_member_id',
        'proposed_guarantor_member_id',
        'proposed_guarantor_name',
        'borrower_member_id',
        'proposed_by_user_id',
        'proposed_by_role',
        'status',
        'freeze_member_request_id',
        'note',
        'accepted_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function outgoingGuarantor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'outgoing_guarantor_member_id');
    }

    public function proposedGuarantor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'proposed_guarantor_member_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'borrower_member_id');
    }

    public function isPendingAdmin(): bool
    {
        return $this->status === self::STATUS_PENDING_ADMIN;
    }

    public function isPendingGuarantor(): bool
    {
        return $this->status === self::STATUS_PENDING_GUARANTOR;
    }

    public function isPending(): bool
    {
        return $this->isPendingAdmin() || $this->isPendingGuarantor();
    }
}
