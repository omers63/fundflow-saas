<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Pages;

use App\Filament\Support\Action;
use App\Filament\Support\MemberSelect;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDay;
use App\Support\StatementSettings;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListMonthlyStatements extends ListRecords
{
    protected static string $resource = MonthlyStatementResource::class;

    protected function getHeaderActions(): array
    {
        $previousPeriod = BusinessDay::today()->subMonthNoOverflow()->format('Y-m');

        return [
            CreateAction::make()
                ->label(__('New'))
                ->icon('heroicon-o-plus-circle'),
            ActionGroup::make([
                Action::make('generate_previous')
                    ->label(__('Last month'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->longRunning()
                    ->longRunningMessage(__('Generating statements for all active members. This can take a minute on large funds.'))
                    ->modalHeading(__('Generate statements for previous month?'))
                    ->modalDescription(function () use ($previousPeriod): string {
                        $autoEmail = StatementSettings::autoEmail()
                            ? __('Auto-email is enabled in settings.')
                            : __('Auto-email is disabled in settings.');

                        return __('Generates statements for :period. :auto_email', [
                            'period' => $previousPeriod,
                            'auto_email' => $autoEmail,
                        ]);
                    })
                    ->action(function () use ($previousPeriod): void {
                        try {
                            @set_time_limit(0);

                            $notify = StatementSettings::autoEmail();
                            $count = app(MonthlyStatementService::class)->generateForAllMembers($previousPeriod, $notify);

                            $message = __(':count statement(s) generated for :period.', [
                                'count' => $count,
                                'period' => $previousPeriod,
                            ]);
                            if ($notify) {
                                $message .= ' '.__('Notifications sent.');
                            }

                            Notification::make()->title($message)->success()->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title(__('Statement generation failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } finally {
                            $this->finishStatementGenerationRun();
                        }
                    }),
                Action::make('generate_for_period')
                    ->label(__('For period'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->longRunning()
                    ->longRunningMessage(__('Generating statements for the selected period. This can take a minute on large funds.'))
                    ->schema([
                        TextInput::make('period')
                            ->label(__('Period (YYYY-MM)'))
                            ->required()
                            ->placeholder($previousPeriod)
                            ->regex('/^\d{4}-\d{2}$/'),
                        Toggle::make('send_notification')
                            ->label(__('Email members after generation'))
                            ->default(StatementSettings::autoEmail()),
                        MemberSelect::configure(
                            Select::make('member_id')
                                ->label(__('Specific member'))
                                ->placeholder(__('All active members')),
                            activeOnly: false,
                        ),
                    ])
                    ->action(function (array $data): void {
                        try {
                            @set_time_limit(0);

                            $svc = app(MonthlyStatementService::class);
                            $period = $data['period'];
                            $notify = (bool) ($data['send_notification'] ?? false);

                            if ($data['member_id'] ?? null) {
                                $member = Member::query()->find((int) $data['member_id']);
                                if ($member === null) {
                                    Notification::make()->title(__('Member not found'))->danger()->send();

                                    return;
                                }

                                $stmt = $svc->generateForMember($member, $period, $notify);
                                Notification::make()
                                    ->title(__('Statement #:id generated for :member.', [
                                        'id' => $stmt->id,
                                        'member' => $member->member_number,
                                    ]))
                                    ->success()
                                    ->send();

                                return;
                            }

                            $count = $svc->generateForAllMembers($period, $notify);
                            Notification::make()
                                ->title(
                                    __('Generated :count statements for :period.', ['count' => $count, 'period' => $period])
                                    .($notify ? ' '.__('Notifications sent.') : '')
                                )
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title(__('Statement generation failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        } finally {
                            $this->finishStatementGenerationRun();
                        }
                    }),
            ])
                ->label(__('Generate'))
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->button()
                ->dropdownPlacement('bottom-end'),
            ActionGroup::make([
                Action::make('notify_unsent')
                    ->label(__('Notify unsent'))
                    ->icon('heroicon-o-bell')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->longRunning()
                    ->longRunningMessage(__('Sending notifications for unsent statements. This can take a minute when PDFs are attached.'))
                    ->modalHeading(__('Notify all unsent statements?'))
                    ->modalDescription(__('Sends in-app notifications for every statement that has not been marked sent yet.'))
                    ->action(function (): void {
                        $this->bulkDeliverUnsent(
                            MonthlyStatementNotification::DELIVERY_NOTIFY,
                            'Notified :sent · skipped :skipped',
                        );
                    }),
                Action::make('email_unsent')
                    ->label(__('Email unsent'))
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->longRunning()
                    ->longRunningMessage(__('Emailing unsent statements. This can take a minute when PDFs are attached.'))
                    ->modalHeading(__('Email all unsent statements?'))
                    ->modalDescription(__('Emails every statement that has not been marked sent yet.'))
                    ->action(function (): void {
                        $this->bulkDeliverUnsent(
                            MonthlyStatementNotification::DELIVERY_EMAIL,
                            'Emailed :sent · skipped :skipped',
                        );
                    }),
                Action::make('notify_latest_unsent')
                    ->label(__('Notify last month'))
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->longRunning()
                    ->longRunningMessage(__('Sending notifications for last month\'s unsent statements. This can take a minute when PDFs are attached.'))
                    ->modalHeading(fn (): string => __('Notify unsent for :period?', [
                        'period' => $previousPeriod,
                    ]))
                    ->modalDescription(__('Only statements for the previous calendar month that are still unsent.'))
                    ->action(function () use ($previousPeriod): void {
                        $this->bulkDeliverUnsent(
                            MonthlyStatementNotification::DELIVERY_NOTIFY,
                            'Notified :sent · skipped :skipped',
                            $previousPeriod,
                        );
                    }),
            ])
                ->label(__('Deliver'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end'),
        ];
    }

    private function bulkDeliverUnsent(
        string $delivery,
        string $resultMessageKey,
        ?string $period = null,
    ): void {
        try {
            @set_time_limit(0);

            $query = MonthlyStatement::query()->whereNull('notified_at')->with('member.user');

            if ($period !== null) {
                $query->where('period', $period);
            }

            $sent = 0;
            $skipped = 0;
            $svc = app(MonthlyStatementService::class);

            $query->each(function (MonthlyStatement $statement) use ($svc, $delivery, &$sent, &$skipped): void {
                if ($svc->sendNotification($statement, $delivery)) {
                    $sent++;

                    return;
                }

                $skipped++;
            });

            Notification::make()
                ->title(__($resultMessageKey, ['sent' => $sent, 'skipped' => $skipped]))
                ->color($sent > 0 ? 'success' : 'warning')
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('Statement delivery failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->finishStatementGenerationRun();
        }
    }

    /**
     * Long statement runs leave Filament action modals mounted unless we force-clear
     * Alpine modal state (same pattern as reconciliation workspace runs).
     */
    private function finishStatementGenerationRun(): void
    {
        if (method_exists($this, 'unmountAction')) {
            $this->unmountAction(false);
        }

        if (property_exists($this, 'mountedActions')) {
            $this->mountedActions = [];
        }

        if (property_exists($this, 'cachedMountedActions')) {
            $this->cachedMountedActions = null;
        }

        $this->resetTable();

        MonthlyStatementResource::dispatchInsightsRefresh($this);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyStatementInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Generate monthly member statements, track delivery, and review period coverage.');
    }
}
