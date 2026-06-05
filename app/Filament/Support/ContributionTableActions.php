<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Services\ContributionLatePostingClearanceService;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
            ->visible(fn (Contribution $record): bool => $record->isDeletableByAdmin())
            ->using(function (Contribution $record): void {
                app(ContributionService::class)->deleteContribution($record);
            })
            ->after(fn (Component $livewire): mixed => self::refreshInsights($livewire));
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalHeading(__('Delete contributions'))
            ->modalDescription(__('Removes the selected manual admin contribution records. Cycle, arrears, and import rows cannot be deleted.'))
            ->before(function (DeleteBulkAction $action, Collection $records): void {
                $blocked = $records->filter(
                    fn (Contribution $record): bool => ! $record->isDeletableByAdmin(),
                );

                if ($blocked->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->title(__('Cannot delete cycle contributions'))
                    ->body(__(':count selected row(s) are system-generated cycle or import records and cannot be removed.', [
                        'count' => $blocked->count(),
                    ]))
                    ->warning()
                    ->send();

                $action->halt();
            })
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

    public static function post(): Action
    {
        return Action::make('post')
            ->label(fn (Contribution $record): string => $record->status === 'failed'
                ? __('Retry post')
                : __('Post'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (Contribution $record): ?string => $record->status === 'failed'
                ? __('Posting failed earlier because member cash was too low. Ensure the cash balance covers the contribution and late fee, then retry.')
                : null)
            ->hidden(fn (Contribution $record): bool => ! in_array($record->status, ['pending', 'failed'], true))
            ->action(function (Contribution $record, Action $action, Component $livewire): void {
                if (
                    ! ActionModalFailure::attempt(
                        $action,
                        fn () => app(ContributionService::class)->postContribution($record),
                        __('Could not post contribution'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Contribution posted successfully'))
                    ->success()
                    ->send();

                self::refreshContributionViews($livewire);
            });
    }

    public static function clearLatePosting(): Action
    {
        return Action::make('clear_late_posting')
            ->label(__('Clear late posting'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Contribution $record): bool => LateSettledArrearsTableStyling::contributionWasSettledLate($record))
            ->modalHeading(__('Clear late posting'))
            ->modalDescription(fn (Contribution $record): string => __('Remove the late flag for :period. Cash and posted status stay unchanged; this period will no longer count toward late payment history.', [
                'period' => $record->period?->translatedFormat('F Y') ?? '#'.$record->id,
            ]))
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (Contribution $record, array $data, Action $action, Component $livewire): void {
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;

                try {
                    $outcome = app(ContributionLatePostingClearanceService::class)->clearContribution($record, $note);
                } catch (\InvalidArgumentException $exception) {
                    ActionModalFailure::present($action, $exception->getMessage(), __('Could not clear late posting'));

                    return;
                }

                if ($outcome === 'already_clear') {
                    Notification::make()
                        ->title(__('Already on time'))
                        ->body(__('This contribution is not marked as late.'))
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Late posting cleared'))
                    ->body(__(':period will count as on time for eligibility.', [
                        'period' => $record->period?->translatedFormat('F Y') ?? '#'.$record->id,
                    ]))
                    ->success()
                    ->send();

                self::refreshContributionViews($livewire);
            });
    }

    public static function clearLatePostingBulk(): BulkAction
    {
        return BulkAction::make('clearLatePostingSelected')
            ->label(__('Clear late posting'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Clear late posting'))
            ->modalDescription(__('Remove the late flag from selected posted rows. Cash and status are unchanged.'))
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (BulkAction $action, Collection $records, array $data, Component $livewire): void {
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;
                $lateRows = $records->filter(
                    fn (mixed $record): bool => $record instanceof Contribution
                    && LateSettledArrearsTableStyling::contributionWasSettledLate($record),
                );

                if ($lateRows->isEmpty()) {
                    ActionModalFailure::present(
                        $action,
                        __('None of the selected rows are posted late contributions.'),
                        __('Could not clear late posting'),
                    );

                    return;
                }

                $summary = app(ContributionLatePostingClearanceService::class)->clearMany($lateRows, $note);

                Notification::make()
                    ->title(__('Bulk clear complete'))
                    ->body(__(':cleared cleared · :already already on time · :skipped skipped', [
                        'cleared' => $summary['cleared'],
                        'already' => $summary['already_clear'],
                        'skipped' => $summary['skipped'],
                    ]))
                    ->color($summary['cleared'] > 0 ? 'success' : 'warning')
                    ->send();

                self::refreshContributionViews($livewire);

                if ($summary['cleared'] === 0) {
                    ActionModalFailure::present(
                        $action,
                        __('No late flags were removed. Selected rows may already be on time or are not posted late contributions.'),
                        __('Bulk clear complete'),
                    );
                }
            });
    }

    public static function postBulk(): BulkAction
    {
        return BulkAction::make('postSelected')
            ->label(__('Post selected'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (BulkAction $action, Collection $records, Component $livewire): void {
                $posted = 0;
                $errors = [];

                foreach ($records as $record) {
                    if (! $record instanceof Contribution || ! in_array($record->status, ['pending', 'failed'], true)) {
                        continue;
                    }

                    try {
                        app(ContributionService::class)->postContribution($record);
                        $posted++;
                    } catch (\InvalidArgumentException $exception) {
                        $period = $record->period?->format('M Y') ?? '#'.$record->id;
                        $errors[] = $period.': '.$exception->getMessage();
                    }
                }

                if ($posted > 0) {
                    Notification::make()
                        ->title(__(':count contribution(s) posted', ['count' => $posted]))
                        ->success()
                        ->send();

                    self::refreshContributionViews($livewire);
                }

                if ($errors !== []) {
                    ActionModalFailure::present(
                        $action,
                        implode("\n", array_slice($errors, 0, 5)).(count($errors) > 5 ? "\n…" : ''),
                        __('Could not post some contributions'),
                    );
                }
            });
    }

    public static function notifyApplyOutcome(string $outcome, ?string $memberName = null, ?Action $action = null): void
    {
        $title = match ($outcome) {
            'applied', 'partial' => __('Contribution applied'),
            'already_contributed' => __('Already recorded'),
            'exempt' => __('Member exempt'),
            default => __('Could not apply'),
        };

        $body = match ($outcome) {
            'applied', 'partial' => $memberName !== null
            ? __('Posted for :name.', ['name' => $memberName])
            : __('Posted successfully.'),
            'insufficient' => __('Insufficient cash balance.'),
            'exempt' => __('Active loan with pending installments.'),
            'already_contributed' => __('This period is already recorded for this member.'),
            default => $outcome,
        };

        if (in_array($outcome, ['applied', 'partial'], true)) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->success()
                ->send();

            return;
        }

        if ($action !== null) {
            ActionModalFailure::present($action, $body, $title);
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }

    public static function refreshContributionViews(Component $livewire): void
    {
        self::refreshInsights($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
