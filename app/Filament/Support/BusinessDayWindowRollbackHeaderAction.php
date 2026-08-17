<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Jobs\Tenant\BusinessDayWindowRollbackJob;
use App\Services\BusinessDayWindowRollbackService;
use App\Support\BusinessDay;
use App\Support\BusinessDayWindowRollbackReport;
use Carbon\Carbon;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;

final class BusinessDayWindowRollbackHeaderAction
{
    /**
     * @var array<string, BusinessDayWindowRollbackReport>
     */
    private static array $reportCache = [];

    public static function make(): Action
    {
        return Action::make('rollback_business_day_window')
            ->label(__('Undo business-day window'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->requiresConfirmation()
            ->longRunning()
            ->longRunningMessage(__('Queueing the rollback. Watch the notification bell when it finishes.'))
            ->modalHeading(__('Undo business-day window'))
            ->modalDescription(__('Keeps activity on the as-of date. Review the list below and uncheck anything you want to keep.'))
            ->modalSubmitActionLabel(__('Undo selected'))
            ->modalWidth(Width::TwoExtraLarge)
            ->extraModalWindowAttributes([
                'class' => 'ff-confirm-modal-window--preview',
            ], merge: true)
            ->mountUsing(function (Action $action, ?Schema $schema): void {
                self::$reportCache = [];
                $action->modalHeading(__('Undo business-day window'));
                $action->modalDescription(__('Keeps activity on the as-of date. Review the list below and uncheck anything you want to keep.'));
                $action->modalIcon('heroicon-o-arrow-uturn-left');
                $action->modalIconColor('danger');
                $schema?->fill();
            })
            ->schema([
                Section::make(__('Keep activity on'))
                    ->compact()
                    ->secondary()
                    ->collapsible()
                    ->schema([
                        DatePicker::make('as_of')
                            ->label(__('As-of date'))
                            ->hiddenLabel()
                            ->native(false)
                            ->format('Y-m-d')
                            ->closeOnDateSelection()
                            ->required()
                            ->live()
                            ->default(fn (): string => BusinessDay::today()->toDateString())
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                self::$reportCache = [];
                                $set('selected', self::reportFor($state)->eventIds());
                            })
                            ->helperText(__('Ledger and collections dated after this day are reversed. Time of day still follows the server clock.')),
                    ]),
                Section::make(__('Events to undo'))
                    ->compact()
                    ->secondary()
                    ->collapsible()
                    ->description(__('Uncheck anything you want to keep. Only checked events are undone.'))
                    ->schema([
                        CheckboxList::make('selected')
                            ->hiddenLabel()
                            ->view('filament.tenant.partials.business-day-window-rollback-events')
                            ->viewData(fn (Get $get): array => [
                                'sections' => self::reportFor($get('as_of'))->sections,
                            ])
                            ->options(fn (Get $get): array => self::optionsFor($get('as_of')))
                            ->default(fn (): array => self::reportFor(null)->eventIds())
                            ->bulkToggleable(),
                    ]),
            ])
            ->successNotification(
                fn (): Notification => Notification::make()
                    ->title(__('Rollback queued'))
                    ->body(__('Reversing activity after the as-of date in the background. Watch the notification bell — large funds can take several minutes.'))
                    ->success()
                    ->persistent()
            )
            ->action(function (array $data, Action $action): void {
                $asOf = Carbon::parse((string) $data['as_of'])->startOfDay();
                $selected = array_values(array_filter((array) ($data['selected'] ?? [])));

                if ($selected === []) {
                    ActionModalFailure::present($action, __('Select at least one event to undo.'), __('Rollback blocked'), allowRetry: true);
                }

                $userId = Auth::guard('tenant')->id();

                BusinessDayWindowRollbackJob::dispatch(
                    $asOf->toDateString(),
                    $selected,
                    $userId !== null ? (int) $userId : null,
                );
            });
    }

    /**
     * @return array<string, string>
     */
    private static function optionsFor(mixed $asOf): array
    {
        $options = [];

        foreach (self::reportFor($asOf)->sections as $section) {
            foreach ($section['events'] as $event) {
                $options[$event['id']] = $event['title'];
            }
        }

        return $options;
    }

    private static function reportFor(mixed $asOf): BusinessDayWindowRollbackReport
    {
        $date = filled($asOf)
            ? Carbon::parse($asOf)->startOfDay()
            : BusinessDay::today();
        $key = $date->toDateString();

        return self::$reportCache[$key] ??= app(BusinessDayWindowRollbackService::class)->preview($date);
    }
}
