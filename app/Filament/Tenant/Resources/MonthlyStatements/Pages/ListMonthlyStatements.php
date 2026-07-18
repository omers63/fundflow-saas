<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Pages;

use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDay;
use App\Support\StatementSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Component;

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
                    ->action(function (Component $livewire) use ($previousPeriod): void {
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
                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
                Action::make('generate_for_period')
                    ->label(__('For period'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->schema([
                        TextInput::make('period')
                            ->label(__('Period (YYYY-MM)'))
                            ->required()
                            ->placeholder($previousPeriod)
                            ->regex('/^\d{4}-\d{2}$/'),
                        Toggle::make('send_notification')
                            ->label(__('Email members after generation'))
                            ->default(StatementSettings::autoEmail()),
                        Select::make('member_id')
                            ->label(__('Specific member'))
                            ->searchable()
                            ->options(fn (): array => Member::query()
                                ->orderBy('member_number')
                                ->get()
                                ->mapWithKeys(fn (Member $member): array => [
                                    $member->id => "{$member->member_number} — {$member->name}",
                                ])
                                ->all())
                            ->placeholder(__('All active members')),
                    ])
                    ->action(function (array $data, Component $livewire): void {
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
                            MonthlyStatementResource::dispatchInsightsRefresh($livewire);

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
                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
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
                    ->modalHeading(__('Notify all unsent statements?'))
                    ->modalDescription(__('Sends in-app notifications for every statement that has not been marked sent yet.'))
                    ->action(function (Component $livewire): void {
                        $this->bulkDeliverUnsent(
                            $livewire,
                            MonthlyStatementNotification::DELIVERY_NOTIFY,
                            'Notified :sent · skipped :skipped',
                        );
                    }),
                Action::make('email_unsent')
                    ->label(__('Email unsent'))
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('Email all unsent statements?'))
                    ->modalDescription(__('Emails every statement that has not been marked sent yet.'))
                    ->action(function (Component $livewire): void {
                        $this->bulkDeliverUnsent(
                            $livewire,
                            MonthlyStatementNotification::DELIVERY_EMAIL,
                            'Emailed :sent · skipped :skipped',
                        );
                    }),
                Action::make('notify_latest_unsent')
                    ->label(__('Notify last month'))
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => __('Notify unsent for :period?', [
                        'period' => $previousPeriod,
                    ]))
                    ->modalDescription(__('Only statements for the previous calendar month that are still unsent.'))
                    ->action(function (Component $livewire) use ($previousPeriod): void {
                        $this->bulkDeliverUnsent(
                            $livewire,
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
        Component $livewire,
        string $delivery,
        string $resultMessageKey,
        ?string $period = null,
    ): void {
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

        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
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
