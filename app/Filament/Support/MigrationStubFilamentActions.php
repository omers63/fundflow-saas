<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\MigrationCycleStub;
use App\Services\MigrationCycleService;
use App\Support\Lang;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class MigrationStubFilamentActions
{
    /**
     * @return array<string, string>
     */
    public static function classificationOptions(): array
    {
        return Lang::transOptions([
            MigrationCycleStub::CLASS_WAIVED => __('Waived'),
            MigrationCycleStub::CLASS_BACKDATED_PAID => __('Backdated paid'),
            MigrationCycleStub::CLASS_BACKDATED_DUE => __('Backdated due'),
            MigrationCycleStub::CLASS_OB_ABSORBED => __('Opening balance absorbed'),
            MigrationCycleStub::CLASS_ESCALATED => __('Escalated'),
        ]);
    }

    /**
     * @return array<int, Select|Textarea>
     */
    public static function classificationFormSchema(): array
    {
        return [
            Select::make('classification')
                ->label(__('Classification'))
                ->options(self::classificationOptions())
                ->required(),
            Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(2),
        ];
    }

    public static function classifyRecordAction(): Action
    {
        return Action::make('classify')
            ->label(__('Classify'))
            ->icon('heroicon-o-tag')
            ->visible(fn(MigrationCycleStub $record): bool => $record->status !== 'closed')
            ->schema(self::classificationFormSchema())
            ->action(function (MigrationCycleStub $record, array $data, MigrationCycleService $migration): void {
                $migration->classifyStub(
                    $record,
                    (string) $data['classification'],
                    null,
                    $data['notes'] ?? null,
                    Auth::guard('tenant')->id(),
                );

                Notification::make()->title(__('Stub classified'))->success()->send();
            });
    }

    public static function classifySelectedBulkAction(): BulkAction
    {
        return BulkAction::make('classifySelected')
            ->label(__('Batch classification'))
            ->icon('heroicon-o-tag')
            ->schema(self::classificationFormSchema())
            ->action(function (Collection $records, array $data, MigrationCycleService $migration): void {
                $count = $migration->classifyStubs(
                    $records,
                    (string) $data['classification'],
                    null,
                    $data['notes'] ?? null,
                    Auth::guard('tenant')->id(),
                );

                Notification::make()
                    ->title(__(':count cycle(s) classified', ['count' => $count]))
                    ->success()
                    ->send();
            });
    }
}
