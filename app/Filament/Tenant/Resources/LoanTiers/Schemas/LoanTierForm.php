<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanTiers\Schemas;

use App\Models\Tenant\FundTier;
use App\Models\Tenant\LoanTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LoanTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }

    /**
     * @return array<int, mixed>
     */
    public static function components(?LoanTier $record = null): array
    {
        return [
            TextInput::make('label')
                ->maxLength(100),
            TextInput::make('min_amount')
                ->numeric()
                ->required()
                ->minValue(0),
            TextInput::make('max_amount')
                ->numeric()
                ->required()
                ->minValue(0),
            TextInput::make('min_monthly_installment')
                ->label(__('Min installment'))
                ->numeric()
                ->required()
                ->minValue(1),
            Select::make('fund_tier_id')
                ->label(__('Fund pool'))
                ->helperText(__('Optional. A loan tier may belong to only one fund pool. Leave blank if unassigned.'))
                ->options(fn (?LoanTier $record): array => self::fundTierOptions($record))
                ->searchable()
                ->preload()
                ->nullable(),
            Toggle::make('is_active')
                ->default(true),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function fundTierOptions(?LoanTier $record): array
    {
        $currentFundTierId = $record?->fund_tier_id;

        return FundTier::query()
            ->where(function ($query) use ($currentFundTierId): void {
                $query->where(function ($active): void {
                    $active->where('is_active', true)->where('tier_number', '>', 0);
                });

                if ($currentFundTierId !== null) {
                    $query->orWhere('id', $currentFundTierId);
                }
            })
            ->orderBy('tier_number')
            ->get()
            ->mapWithKeys(fn (FundTier $tier): array => [
                $tier->id => $tier->label,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillData(LoanTier $record): array
    {
        return [
            'label' => $record->getAttributes()['label'] ?? null,
            'min_amount' => $record->min_amount,
            'max_amount' => $record->max_amount,
            'min_monthly_installment' => $record->min_monthly_installment,
            'fund_tier_id' => $record->fund_tier_id,
            'is_active' => $record->is_active,
        ];
    }
}
