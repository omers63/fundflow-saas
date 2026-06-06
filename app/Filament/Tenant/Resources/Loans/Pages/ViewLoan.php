<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanViewInfolist;
use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Models\Tenant\Loan;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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

    public function infolist(Schema $schema): Schema
    {
        return LoanViewInfolist::configure($schema);
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Loan $record */
        $record = parent::resolveRecord($key);

        return $record->load([
            'member',
            'guarantor',
            'loanTier',
            'fundTier',
            'approvedBy',
            'installments',
        ])->loadCount(['disbursements', 'installments']);
    }
}
