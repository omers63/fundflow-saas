<?php

namespace App\Models\Tenant;

use App\Support\LoanSettings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return static::where('group', $group)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function set(string $group, string $key, mixed $value): void
    {
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
        return static::where('group', $group)
            ->pluck('value', 'key')
            ->all();
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
}
