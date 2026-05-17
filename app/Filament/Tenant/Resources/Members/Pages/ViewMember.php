<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Support\MemberDelinquencyActions;
use App\Filament\Tenant\Resources\Members\Concerns\InteractsWithMemberContributionHeaderActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewMember extends ViewRecord
{
    use InteractsWithMemberContributionHeaderActions;

    protected static string $resource = MemberResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->memberContributionHeaderActions(),
            ...MemberDelinquencyActions::forMemberView(),
            EditAction::make(),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Member Details'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('member_number')
                            ->label('Member #'),
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone')
                            ->placeholder(__('—')),
                        TextEntry::make('monthly_contribution_amount')
                            ->label('Monthly contribution')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                        TextEntry::make('joined_at')
                            ->label('Joined')
                            ->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                            ->color(fn (string $state): string => Member::statusBadgeColor($state)),
                        TextEntry::make('parent.name')
                            ->label('Parent member')
                            ->placeholder(__('Independent')),
                    ]),
                Section::make(__('Delinquency'))
                    ->visible(fn (Member $record): bool => app(LoanDelinquencyService::class)->memberArrearsSummary($record)['has_arrears']
                        || $record->status === 'delinquent')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('delinquency_status')
                            ->label(__('Status'))
                            ->state(fn (Member $record): string => Member::statusOptions()[$record->status] ?? $record->status)
                            ->badge()
                            ->color(fn (Member $record): string => Member::statusBadgeColor($record->status)),
                        TextEntry::make('overdue_installments')
                            ->label(__('Overdue installments'))
                            ->state(fn (Member $record): string => (string) app(LoanDelinquencyService::class)->memberArrearsSummary($record)['overdue_installment_count']),
                        TextEntry::make('unpaid_contributions')
                            ->label(__('Unpaid contribution periods'))
                            ->state(function (Member $record): string {
                                $labels = app(LoanDelinquencyService::class)->memberArrearsSummary($record)['unpaid_contribution_periods'];

                                return $labels !== [] ? implode(', ', $labels) : __('—');
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
