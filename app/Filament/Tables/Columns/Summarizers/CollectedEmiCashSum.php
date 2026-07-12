<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns\Summarizers;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Footer total for EMI collection tables: sums {@see LoanInstallment::collectedCashAmount()}
 * across the filtered result set (not raw schedule {@see LoanInstallment::$amount}).
 */
class CollectedEmiCashSum extends Sum
{
    protected function setUp(): void
    {
        parent::setUp();

        $currency = fn(): string => Setting::get('general', 'currency', 'USD');

        $this
            ->using(function (): float {
                $query = $this->getQuery();

                if (!$query instanceof Builder) {
                    return 0.0;
                }

                return round(
                    $query->clone()
                        ->get()
                        ->sum(fn(LoanInstallment $installment): float => $installment->collectedCashAmount()),
                    2,
                );
            })
            ->formatStateUsing(fn($state): ?string => MoneyDisplay::tableSummaryHtml($state, $currency()))
            ->html();
    }

    /**
     * Skip Filament's SQL {@see Sum::getSelectStatements()} — collected cash cannot be summed as {@see LoanInstallment::$amount}.
     *
     * @return array<string, string>
     */
    public function getSelectStatements(string $column): array
    {
        return [];
    }
}
