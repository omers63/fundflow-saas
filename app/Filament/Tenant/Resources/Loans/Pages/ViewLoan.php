<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanViewInfolist;
use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Models\Tenant\Loan;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewLoan extends ViewRecord
{
    use RefreshesResourceRecord;

    protected static string $resource = LoanResource::class;

    public function getHeading(): string
    {
        return __('Loan #:id', ['id' => $this->record->getKey()]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $loan = $this->getRecord();
        $status = Loan::statusOptions()[$loan->status] ?? $loan->status;

        return __(':member · :status', [
            'member' => $loan->member?->name ?? __('Unknown member'),
            'status' => $status,
        ]);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Details');
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedDocumentText;
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

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ff-tenant-loan-detail',
        ];
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
