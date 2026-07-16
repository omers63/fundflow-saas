<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundTiers\Schemas;

use App\Models\Tenant\FundTier;
use App\Models\Tenant\LoanTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FundTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }

    /**
     * @return array<int, mixed>
     */
    public static function components(?FundTier $record = null): array
    {
        return [
            TextInput::make('label')
                ->maxLength(100),
            TextInput::make('percentage')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
            Select::make('loan_tier_ids')
                ->label(__('Linked loan tiers'))
                ->helperText(__('A loan tier can belong to only one fund tier. Unassigned active loan tiers are listed here.'))
                ->options(fn (?FundTier $record): array => self::loanTierOptions($record))
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (?FundTier $record): bool => ! ($record?->isEmergency() ?? false))
                ->disabled(fn (?FundTier $record): bool => $record?->isEmergency() ?? false),
            Toggle::make('is_active')
                ->default(true),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function loanTierOptions(?FundTier $record): array
    {
        $currentId = $record?->id;

        return LoanTier::query()
            ->where('is_active', true)
            ->where(function ($query) use ($currentId): void {
                $query->whereNull('fund_tier_id');

                if ($currentId !== null) {
                    $query->orWhere('fund_tier_id', $currentId);
                }
            })
            ->orderBy('tier_number')
            ->get()
            ->mapWithKeys(fn (LoanTier $tier): array => [
                $tier->id => $tier->label.' ('.$tier->range.')',
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillData(FundTier $record): array
    {
        return [
            'label' => $record->getAttributes()['label'] ?? null,
            'percentage' => $record->percentage,
            'is_active' => $record->is_active,
            'loan_tier_ids' => $record->loanTiers()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    public static function extractLoanTierIds(array $data): array
    {
        $loanTierIds = collect($data['loan_tier_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        unset($data['loan_tier_ids']);

        return [$data, $loanTierIds];
    }
}
