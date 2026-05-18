<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\LoanCalculator;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Widgets\MemberLoanInsightsWidget;
use App\Services\LoanService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListMyLoans extends ListRecords
{
    protected static string $resource = MyLoanResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MemberLoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Track your applications, active loan, and repayment progress.');
    }

    protected function getHeaderActions(): array
    {
        $member = auth('tenant')->user()?->member;

        return [
            Action::make('calculator')
                ->label(__('Loan calculator'))
                ->icon('heroicon-o-calculator')
                ->url(LoanCalculator::getUrl())
                ->color('gray'),
            Action::make('applyForLoan')
                ->label(__('Apply for loan'))
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url(ApplyForLoan::getUrl())
                ->visible(fn (): bool => $member !== null && app(LoanService::class)->checkEligibility($member)['eligible']),
        ];
    }
}
