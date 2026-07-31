<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Standing monthly contribution election tiers (member + dependent allocation).
 *
 * Options are generated from min → max in denomination steps (defaults: 500 → 10_500 by 500).
 */
final class ContributionAmountSettings
{
    public const GROUP = 'contribution_amounts';

    public const DEFAULT_MIN = 500;

    public const DEFAULT_STEP = 500;

    public const DEFAULT_MAX = 10_500;

    /**
     * @return array{min_amount: int, step_amount: int, max_amount: int}
     */
    public static function defaults(): array
    {
        return [
            'min_amount' => self::DEFAULT_MIN,
            'step_amount' => self::DEFAULT_STEP,
            'max_amount' => self::DEFAULT_MAX,
        ];
    }

    public static function minAmount(): int
    {
        return max(1, (int) self::get('min_amount', self::DEFAULT_MIN));
    }

    public static function stepAmount(): int
    {
        return max(1, (int) self::get('step_amount', self::DEFAULT_STEP));
    }

    public static function maxAmount(): int
    {
        return max(self::minAmount(), (int) self::get('max_amount', self::DEFAULT_MAX));
    }

    /**
     * Ascending list of electable contribution amounts.
     *
     * @return list<int>
     */
    public static function steps(): array
    {
        $min = self::minAmount();
        $step = self::stepAmount();
        $max = self::maxAmount();

        $amounts = [];

        for ($amount = $min; $amount <= $max; $amount += $step) {
            $amounts[] = $amount;
        }

        if ($amounts === [] || end($amounts) !== $max) {
            // Include max when it is not aligned to the step series (e.g. max lowered mid-step).
            if ($max >= $min && ! in_array($max, $amounts, true)) {
                $amounts[] = $max;
                sort($amounts);
            }
        }

        return $amounts;
    }

    public static function isValidAmount(int $amount): bool
    {
        return in_array($amount, self::steps(), true);
    }

    /**
     * @return array{contribution_amount_min: int, contribution_amount_step: int, contribution_amount_max: int}
     */
    public static function forForm(): array
    {
        return [
            'contribution_amount_min' => self::minAmount(),
            'contribution_amount_step' => self::stepAmount(),
            'contribution_amount_max' => self::maxAmount(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        $min = max(1, (int) ($state['contribution_amount_min'] ?? self::DEFAULT_MIN));
        $step = max(1, (int) ($state['contribution_amount_step'] ?? self::DEFAULT_STEP));
        $max = max($min, (int) ($state['contribution_amount_max'] ?? self::DEFAULT_MAX));

        Setting::set(self::GROUP, 'min_amount', $min);
        Setting::set(self::GROUP, 'step_amount', $step);
        Setting::set(self::GROUP, 'max_amount', $max);
    }

    public static function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $value) {
            if (Setting::get(self::GROUP, $key) === null) {
                Setting::set(self::GROUP, $key, $value);
            }
        }
    }

    private static function get(string $key, int $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
