<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Services\ContributionService;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Throwable;

final class ContributionTableActions
{
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(__('Delete contribution'))
            ->modalDescription(fn (Contribution $record): string => ContributionService::deleteModalDescription($record))
            ->using(function (Contribution $record): void {
                app(ContributionService::class)->deleteContribution($record);
            })
            ->after(fn (Component $livewire): mixed => self::refreshInsights($livewire));
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalHeading(__('Delete contributions'))
            ->modalDescription(__('Removes the selected contribution records. Posted contributions are reversed on member cash, fund, and master pool accounts before removal.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $service = app(ContributionService::class);

                foreach ($records as $record) {
                    if (! $record instanceof Contribution) {
                        continue;
                    }

                    try {
                        $service->deleteContribution($record);
                    } catch (Throwable $exception) {
                        $period = $record->period?->format('M Y') ?? '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $period.': '.$exception->getMessage(),
                        );
                    }
                }
            })
            ->after(fn (Component $livewire): mixed => self::refreshInsights($livewire));
    }

    private static function refreshInsights(Component $livewire): mixed
    {
        ContributionResource::dispatchInsightsRefresh($livewire);
        MemberResource::dispatchMemberDetailInsightsRefresh($livewire);

        return null;
    }
}
