<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Models\Tenant\Loan;
use App\Services\LoanInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Route;

class MemberLoanInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-loan-insights';

    protected int|string|array $columnSpan = 'full';

    public string $context = 'member_portfolio';

    public Loan|int|null $record = null;

    /**
     * @return array<string, mixed>
     */
    public function resolvedContext(): string
    {
        $route = Route::currentRouteName() ?? '';

        if (str_contains($route, 'my-loans.view')) {
            return 'loan_detail';
        }

        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $memberId = CurrentMember::id();

        if ($this->resolvedContext() === 'loan_detail') {
            $loan = $this->record instanceof Loan
                ? $this->record
                : (is_int($this->record) ? Loan::query()->find($this->record) : null);

            return $loan ? app(LoanInsightsService::class)->loanDetailSnapshot($loan) : [];
        }

        return app(LoanInsightsService::class)->memberPortfolioSnapshot($memberId);
    }
}
