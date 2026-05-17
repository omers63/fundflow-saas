<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public function getHeading(): string
    {
        return __('Loan #:id', ['id' => $this->record->getKey()]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            LoanViewInsights::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'record' => $this->getRecord(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...LoanFilamentActions::workflowActions(),
            EditAction::make()
                ->hidden(fn (): bool => ! in_array($this->record->status, ['pending', 'approved'], true)),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema->schema([
            Section::make(__('Loan summary'))
                ->columns(3)
                ->schema([
                    TextEntry::make('member.name')
                        ->label(__('Member')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextEntry::make('amount_requested')
                        ->label(__('Requested'))
                        ->money($currency),
                    TextEntry::make('amount_approved')
                        ->label(__('Approved'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextEntry::make('amount_disbursed')
                        ->label(__('Disbursed'))
                        ->money($currency),
                    TextEntry::make('outstanding')
                        ->label(__('Outstanding'))
                        ->state(fn (Loan $record): float => $record->getOutstandingBalance())
                        ->money($currency),
                    TextEntry::make('loanTier.label')
                        ->label(__('Loan tier'))
                        ->placeholder(__('—')),
                    TextEntry::make('fundTier.label')
                        ->label(__('Fund tier'))
                        ->placeholder(__('—')),
                    TextEntry::make('queue_position')
                        ->label(__('Queue position'))
                        ->placeholder(__('—')),
                    TextEntry::make('guarantor.name')
                        ->label(__('Guarantor'))
                        ->placeholder(__('—')),
                    TextEntry::make('guarantor_liability_transferred_at')
                        ->label(__('Guarantor liability'))
                        ->formatStateUsing(fn ($state, Loan $record): string => $state !== null
                            ? __('Transferred :date', ['date' => Carbon::parse($state)->translatedFormat('j M Y H:i')])
                            : __('Borrower (standard cycle)'))
                        ->visible(fn (Loan $record): bool => $record->status === 'active' && $record->guarantor_member_id !== null),
                    TextEntry::make('late_repayment_count')
                        ->label(__('Late repayments'))
                        ->visible(fn (Loan $record): bool => $record->status === 'active'),
                    TextEntry::make('installments_count')
                        ->label(__('Installments'))
                        ->placeholder(__('—')),
                    TextEntry::make('purpose')
                        ->placeholder(__('—'))
                        ->columnSpanFull(),
                    TextEntry::make('rejection_reason')
                        ->label(__('Rejection reason'))
                        ->visible(fn (Loan $record): bool => $record->status === 'rejected')
                        ->columnSpanFull(),
                    TextEntry::make('applied_at')
                        ->dateTime(),
                    TextEntry::make('approved_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('disbursed_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('payout_at')
                        ->label(__('Bank payout'))
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('settled_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                ]),
        ]);
    }
}
