<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\LoanInstallment;
use App\Services\ContributionCycleService;
use Filament\Tables\Columns\TextColumn;

final class LoanInstallmentTableColumns
{
    public static function cycle(?string $label = null): TextColumn
    {
        return TextColumn::make('contribution_cycle')
            ->label($label ?? __('Cycle'))
            ->state(fn(LoanInstallment $record): ?string => self::cycleLabel($record))
            ->placeholder(__('—'))
            ->searchable(false)
            ->sortable(false);
    }

    public static function cycleLabel(LoanInstallment $record): ?string
    {
        if ($record->due_date === null) {
            return null;
        }

        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->cyclePeriodForDueDate($record->due_date);

        return $cycles->periodLabel($month, $year);
    }
}
