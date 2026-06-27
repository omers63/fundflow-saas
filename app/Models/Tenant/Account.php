<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Relations\MasterAccountBankLinesAwaitingPostingRelation;
use App\Models\Tenant\Relations\MasterAccountPendingClearanceRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    public const TYPE_LOAN = 'loan';

    public const TYPES = ['cash', 'fund', 'bank', 'expense', 'fees', 'invest', 'loan', 'suspense'];

    public static function masterSuspense(): ?self
    {
        return static::where('is_master', true)->where('type', 'suspense')->first();
    }

    protected $fillable = [
        'member_id',
        'loan_id',
        'type',
        'name',
        'balance',
        'is_master',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_master' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeWithLastActivityAt(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        return $query->addSelect([
            'last_activity_at' => Transaction::query()
                ->selectRaw('max(transacted_at)')
                ->whereColumn('account_id', 'accounts.id'),
        ]);
    }

    public static function lastLedgerActivitySubquery(): string
    {
        return '(select max(transacted_at) from transactions where transactions.account_id = accounts.id)';
    }

    public function scopeWhereLastActivityDateOnOrAfter(Builder $query, string $date): Builder
    {
        return $query->whereRaw('date('.self::lastLedgerActivitySubquery().') >= ?', [$date]);
    }

    public function scopeWhereLastActivityDateOnOrBefore(Builder $query, string $date): Builder
    {
        return $query->whereRaw('date('.self::lastLedgerActivitySubquery().') <= ?', [$date]);
    }

    public function pendingOperationalClearanceBankTransactions(): MasterAccountPendingClearanceRelation
    {
        return new MasterAccountPendingClearanceRelation($this);
    }

    public function bankLinesAwaitingPosting(): MasterAccountBankLinesAwaitingPostingRelation
    {
        return new MasterAccountBankLinesAwaitingPostingRelation($this);
    }

    public function scopeMaster($query)
    {
        return $query->where('is_master', true);
    }

    public function scopeMemberAccounts($query)
    {
        return $query->where('is_master', false);
    }

    public static function masterCash(): ?self
    {
        return static::where('is_master', true)->where('type', 'cash')->first();
    }

    public static function masterFund(): ?self
    {
        return static::where('is_master', true)->where('type', 'fund')->first();
    }

    public static function masterBank(): ?self
    {
        return static::where('is_master', true)->where('type', 'bank')->first();
    }

    public static function masterExpense(): ?self
    {
        return static::where('is_master', true)->where('type', 'expense')->first();
    }

    public static function masterFees(): ?self
    {
        return static::where('is_master', true)->where('type', 'fees')->first();
    }

    public static function masterInvest(): ?self
    {
        return static::where('is_master', true)->where('type', 'invest')->first();
    }

    /**
     * @return list<array{type: string, name: string}>
     */
    public static function defaultMasterAccountDefinitions(): array
    {
        return [
            ['type' => 'cash', 'name' => 'Master Cash'],
            ['type' => 'fund', 'name' => 'Master Fund'],
            ['type' => 'bank', 'name' => 'Master Bank'],
            ['type' => 'expense', 'name' => 'Master Expense'],
            ['type' => 'fees', 'name' => 'Master Fees'],
            ['type' => 'invest', 'name' => 'Master Invest'],
            ['type' => 'suspense', 'name' => 'Master Suspense'],
        ];
    }

    public static function ensureDefaultMasterAccounts(): void
    {
        foreach (self::defaultMasterAccountDefinitions() as $account) {
            static::firstOrCreate(
                ['type' => $account['type'], 'is_master' => true],
                ['member_id' => null, 'name' => $account['name'], 'balance' => 0],
            );
        }
    }

    public static function ensureMasterSuspense(): self
    {
        static::ensureDefaultMasterAccounts();

        $suspense = static::masterSuspense();

        if ($suspense === null) {
            throw new \RuntimeException('Master suspense account is not configured.');
        }

        if ($suspense->name !== 'Master Suspense') {
            $suspense->update(['name' => 'Master Suspense']);
        }

        return $suspense->fresh();
    }

    /**
     * User-facing label for master accounts (Filament list/view); ledger name stays unchanged.
     */
    public function displayLabel(): string
    {
        if (! $this->is_master) {
            return $this->name;
        }

        return match ($this->type) {
            'cash' => __('Master Cash'),
            'fund' => __('Master Fund'),
            'bank' => __('Master Bank'),
            'expense' => __('Master Expense'),
            'fees' => __('Master Fees'),
            'invest' => __('Master Invest'),
            'suspense' => __('Master Suspense'),
            default => $this->name,
        };
    }

    /**
     * Member-portal label for cash / fund / loan accounts (not the internal ledger name).
     */
    public function memberFacingLabel(): string
    {
        if ($this->is_master) {
            return $this->displayLabel();
        }

        return match ($this->type) {
            'cash' => __('Cash account'),
            'fund' => __('Fund account'),
            'loan' => __('Loan account'),
            default => $this->displayLabel(),
        };
    }
}
