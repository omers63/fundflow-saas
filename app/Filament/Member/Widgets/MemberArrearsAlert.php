<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MemberArrearsAlert extends Widget
{
    protected static bool $isDiscovered = true;

    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.member.widgets.member-arrears-alert';

    public static function canView(): bool
    {
        $member = CurrentMember::get();

        if (! $member instanceof Member) {
            return false;
        }

        return app(LoanDelinquencyService::class)->memberArrearsSummary($member)['has_arrears'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $summary = $member instanceof Member
            ? app(LoanDelinquencyService::class)->memberArrearsSummary($member)
            : ['has_arrears' => false, 'is_delinquent' => false, 'overdue_installment_count' => 0, 'unpaid_contribution_periods' => [], 'unpaid_contribution_details' => []];

        return [
            'summary' => $summary,
            'loansUrl' => MyLoanResource::getUrl('index'),
            'contributionsUrl' => MyContributionResource::getUrl('index'),
        ];
    }
}
