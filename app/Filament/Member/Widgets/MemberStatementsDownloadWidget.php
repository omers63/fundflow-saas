<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Pages\MemberActivityPage;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\MonthlyStatement;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MemberStatementsDownloadWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.member.widgets.member-statements-download';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();

        $latestStatement = $member !== null
            ? MonthlyStatement::query()
                ->where('member_id', $member->id)
                ->latest('period')
                ->first()
            : null;

        $activeLoan = $member !== null
            ? Loan::query()
                ->where('member_id', $member->id)
                ->where('status', 'active')
                ->latest('disbursed_at')
                ->first()
            : null;

        return [
            'activityPageUrl' => MemberActivityPage::getUrl(),
            'activityExportRoute' => route('tenant.member.activity.export'),
            'latestStatementPdfUrl' => $latestStatement !== null
                ? route('tenant.member.statement.pdf', $latestStatement)
                : null,
            'latestStatementPeriod' => $latestStatement?->period_formatted,
            'loanSchedulePdfUrl' => $activeLoan !== null
                ? route('tenant.member.loan.schedule.pdf', $activeLoan)
                : null,
            'loansUrl' => MyLoanResource::getUrl('index'),
        ];
    }
}
