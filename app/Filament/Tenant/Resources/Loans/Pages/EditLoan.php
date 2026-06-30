<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanForm;
use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Models\Tenant\Loan;
use App\Support\LoanFundExcessDisposition;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EditLoan extends EditRecord
{
    use RefreshesResourceRecord;

    protected static string $resource = LoanResource::class;

    public function getTitle(): string|Htmlable
    {
        $loan = $this->getRecord();

        return match ($loan->status) {
            'pending' => __('Review loan application #:id', ['id' => $loan->getKey()]),
            'approved', 'partially_disbursed' => __('Disburse loan #:id', ['id' => $loan->getKey()]),
            default => __('Edit loan #:id', ['id' => $loan->getKey()]),
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        $loan = $this->getRecord();
        $status = Loan::statusOptions()[$loan->status] ?? $loan->status;
        $member = $loan->member?->name ?? __('Unknown member');

        $context = match ($loan->status) {
            'pending' => __('Check eligibility, adjust details if needed, then approve or reject.'),
            'approved', 'partially_disbursed' => __('Release approved funds when the bank transfer is ready.'),
            default => null,
        };

        $headline = __(':member · :status', [
            'member' => $member,
            'status' => $status,
        ]);

        return $context !== null
            ? $headline.' — '.$context
            : $headline;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return match ($this->getRecord()->status) {
            'pending' => __('Review'),
            'approved', 'partially_disbursed' => __('Disbursement'),
            default => __('Details'),
        };
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return match ($this->getRecord()->status) {
            'pending' => Heroicon::OutlinedClipboardDocumentCheck,
            'approved', 'partially_disbursed' => Heroicon::OutlinedBanknotes,
            default => Heroicon::OutlinedDocumentText,
        };
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        $classes = [
            ...parent::getPageClasses(),
            'ff-tenant-loan-detail',
        ];

        if ($this->getRecord()->status === 'pending') {
            $classes[] = 'ff-tenant-loan-review';
        }

        return $classes;
    }

    public function form(Schema $schema): Schema
    {
        $loanResolver = fn (): Loan => $this->getRecord();

        return match ($this->getRecord()->status) {
            'pending' => LoanForm::configureReviewForm($schema, $loanResolver),
            'approved', 'partially_disbursed' => LoanForm::configureApprovedProcessingForm($schema, $loanResolver),
            default => LoanForm::configureEditForm($schema),
        };
    }

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
        $record = $this->getRecord();

        if ($record->status === 'pending') {
            return [
                LoanFilamentActions::approve(),
                LoanFilamentActions::reject(),
                LoanFilamentActions::cancel(),
                Action::make('viewFullRecord')
                    ->label(__('Full record'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(LoanResource::getUrl('view', ['record' => $record])),
                DeleteAction::make(),
            ];
        }

        if (in_array($record->status, ['approved', 'partially_disbursed'], true)) {
            return [
                LoanFilamentActions::disburse(),
                Action::make('viewFullRecord')
                    ->label(__('Full record'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(LoanResource::getUrl('view', ['record' => $record])),
            ];
        }

        return [
            LoanFilamentActions::cashOutSplitExcessFund(),
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->status === 'pending'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['excess_fund_disposition'] = LoanFundExcessDisposition::fromCashOutFlag(
            (bool) ($data['cash_out_excess_fund'] ?? false),
        );

        if (! array_key_exists('grace_cycles', $data) || $data['grace_cycles'] === null) {
            $data['grace_cycles'] = ($data['has_grace_cycle'] ?? true) ? 1 : 0;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['excess_fund_disposition'])) {
            $data['cash_out_excess_fund'] = LoanFundExcessDisposition::toCashOutFlag(
                (string) $data['excess_fund_disposition'],
            );
            unset($data['excess_fund_disposition']);
        }

        if (array_key_exists('grace_cycles', $data)) {
            $graceCycles = (int) $data['grace_cycles'];
            $data['has_grace_cycle'] = $graceCycles > 0;
            $data['grace_cycles'] = $graceCycles;
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        if ($this->getRecord()->status === 'pending') {
            return __('Application updated');
        }

        return parent::getSavedNotificationTitle();
    }
}
