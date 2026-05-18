<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyGuaranteedLoans\Pages;

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\RelationManagers\InstallmentsRelationManager;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Support\Loans\LoanUserFacingStage;
use App\Support\Tenant\CurrentMember;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMyGuaranteedLoan extends ViewRecord
{
    protected static string $resource = MyGuaranteedLoanResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $member = CurrentMember::get();
        if ($member === null || (int) $this->record->guarantor_member_id !== (int) $member->id) {
            abort(403);
        }
    }

    public function getHeading(): string
    {
        return __('Guaranteed loan #:id', ['id' => $this->record->getKey()]);
    }

    public function getSubheading(): ?string
    {
        return $this->record->member?->name;
    }

    public function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public function schema(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema->schema([
            Section::make(__('Guarantor summary'))
                ->columns(2)
                ->schema([
                    TextEntry::make('member.name')
                        ->label(__('Borrower')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, Loan $record): string => LoanUserFacingStage::memberListStatusLabel($record))
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextEntry::make('amount_requested')
                        ->label(__('Amount'))
                        ->money($currency),
                    TextEntry::make('guarantor_liability_transferred_at')
                        ->label(__('Liability status'))
                        ->formatStateUsing(fn ($state): string => $state
                            ? __('Liability transferred to you')
                            : __('Borrower is primarily liable')),
                    TextEntry::make('purpose')
                        ->columnSpanFull()
                        ->placeholder(__('—')),
                ]),
        ]);
    }
}
