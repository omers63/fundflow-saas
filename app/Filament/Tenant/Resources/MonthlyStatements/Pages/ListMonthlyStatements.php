<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Pages;

use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Models\Tenant\Member;
use App\Services\MonthlyStatementService;
use App\Support\StatementSettings;
use Filament\Actions\Action;
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
        return [
            CreateAction::make()
                ->label(__('New statement'))
                ->icon('heroicon-o-plus-circle'),
            Action::make('generate_and_send')
                ->label(__('Generate & send previous month'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(__('Generate statements for previous month?'))
                ->modalDescription(function (): string {
                    $period = now()->subMonthNoOverflow()->format('Y-m');
                    $autoEmail = StatementSettings::autoEmail()
                        ? __('Auto-email is enabled in settings.')
                        : __('Auto-email is disabled in settings.');

                    return __('Generates statements for :period. :auto_email', [
                        'period' => $period,
                        'auto_email' => $autoEmail,
                    ]);
                })
                ->action(function (Component $livewire): void {
                    $period = now()->subMonthNoOverflow()->format('Y-m');
                    $notify = StatementSettings::autoEmail();
                    $count = app(MonthlyStatementService::class)->generateForAllMembers($period, $notify);

                    $message = __(':count statement(s) generated for :period.', ['count' => $count, 'period' => $period]);
                    if ($notify) {
                        $message .= ' '.__('Notifications sent.');
                    }

                    Notification::make()->title($message)->success()->send();
                    MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                }),
            Action::make('generate_for_period')
                ->label(__('Generate for period'))
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->schema([
                    TextInput::make('period')
                        ->label(__('Period (YYYY-MM)'))
                        ->required()
                        ->placeholder(now()->subMonthNoOverflow()->format('Y-m'))
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
        ];
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
