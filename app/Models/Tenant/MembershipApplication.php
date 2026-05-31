<?php

namespace App\Models\Tenant;

use App\Support\Lang;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MembershipApplication extends Model
{
    use HasFactory;

    public const APPLICATION_TYPES = ['new', 'resume', 'renew'];

    protected $fillable = [
        'name',
        'email',
        'household_email',
        'parent_application_id',
        'member_id',
        'password',
        'phone',
        'application_type',
        'gender',
        'marital_status',
        'national_id',
        'date_of_birth',
        'address',
        'city',
        'home_phone',
        'work_phone',
        'mobile_phone',
        'occupation',
        'employer',
        'work_place',
        'residency_place',
        'monthly_income',
        'bank_account_number',
        'iban',
        'membership_date',
        'next_of_kin_name',
        'next_of_kin_phone',
        'message',
        'application_form_path',
        'membership_fee_amount',
        'membership_fee_transfer_date',
        'membership_fee_transfer_reference',
        'membership_fee_required_amount',
        'membership_fee_receipt_path',
        'import_arrears_cutoff_date',
        'import_cutoff_cash_balance',
        'import_cutoff_fund_balance',
        'status',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'membership_date' => 'date',
            'monthly_income' => 'decimal:2',
            'membership_fee_amount' => 'decimal:2',
            'membership_fee_required_amount' => 'decimal:2',
            'membership_fee_transfer_date' => 'date',
            'import_arrears_cutoff_date' => 'date',
            'import_cutoff_cash_balance' => 'decimal:2',
            'import_cutoff_fund_balance' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function parentApplication(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_application_id');
    }

    public function dependentApplications(): HasMany
    {
        return $this->hasMany(self::class, 'parent_application_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function subscriptionFeeBankTransaction(): HasOne
    {
        return $this->hasOne(BankTransaction::class, 'membership_application_id');
    }

    public function isHouseholdDependent(): bool
    {
        return $this->parent_application_id !== null;
    }

    /**
     * Applications created via the CSV importer always store an arrears cut-off date.
     */
    public function wasImportedFromCsv(): bool
    {
        return $this->import_arrears_cutoff_date !== null;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /** @return array<string, string> */
    public static function applicationTypeOptions(): array
    {
        return Lang::transOptions([
            'new' => 'New',
            'resume' => 'Resume',
            'renew' => 'Renew',
        ]);
    }

    /** @return array<string, string> */
    public static function genderOptions(): array
    {
        return Lang::transOptions([
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
        ]);
    }

    /** @return array<string, string> */
    public static function maritalStatusOptions(): array
    {
        return Lang::transOptions([
            'single' => 'Single',
            'married' => 'Married',
            'divorced' => 'Divorced',
            'widowed' => 'Widowed',
            'other' => 'Other',
        ]);
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return Lang::transOptions([
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ]);
    }
}
