<?php

namespace App\Models\Tenant;

use App\Services\Governance\MotionEnforcementGate;
use App\Models\Tenant\Builders\SettingBuilder;
use App\Support\LoanSettings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    /**
     * Per-process snapshot of the settings table, keyed by tenant: tenant id => group => key => value.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $memo = [];

    protected static function booted(): void
    {
        static::saved(static fn () => static::flushMemo());
        static::deleted(static fn () => static::flushMemo());
    }

    public function newEloquentBuilder($query): SettingBuilder
    {
        return new SettingBuilder($query);
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function snapshot(): array
    {
        $tenantKey = (string) (function_exists('tenant') ? tenant('id') : '');

        if (! isset(self::$memo[$tenantKey])) {
            $rows = [];

            foreach (static::query()->get(['group', 'key', 'value']) as $row) {
                $rows[$row->group][$row->key] = $row->value;
            }

            self::$memo[$tenantKey] = $rows;
        }

        return self::$memo[$tenantKey];
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return (self::snapshot()[$group][$key] ?? null) ?? $default;
    }

    public static function set(string $group, string $key, mixed $value, ?int $approvedMotionId = null): void
    {
        app(MotionEnforcementGate::class)->assertSettingChangeAllowed($group, $key, $approvedMotionId);

        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getGroup(string $group): array
    {
        return self::snapshot()[$group] ?? [];
    }

    public static function contributionCycleStartDay(): int
    {
        return (int) static::get('contribution', 'cycle_start_day', 6);
    }

    public static function loanSettlementThreshold(): float
    {
        return LoanSettings::settlementThreshold();
    }

    public static function loanMinFundBalance(): float
    {
        return LoanSettings::minFundBalance();
    }

    public static function loanEligibilityMonths(): int
    {
        return LoanSettings::eligibilityMonths();
    }

    public static function loanMaxBorrowMultiplier(): float
    {
        return LoanSettings::maxBorrowMultiplier();
    }

    public static function loanDefaultGraceCycles(): int
    {
        return LoanSettings::defaultGraceCycles();
    }

    public static function loanGuarantorTransferMissedThreshold(): int
    {
        return LoanSettings::guarantorTransferMissedThreshold();
    }
}
