<?php

use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Filament\Member\Pages\MyNotificationPreferencesPage;
use App\Filament\Member\Pages\SupportPage;
use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Filament\Member\Support\MemberNavigation;

test('member navigation group keys match legacy panel order', function () {
    expect(MemberNavigation::groupKeys())->toBe([
        MemberNavigation::GROUP_MY_FINANCE,
        MemberNavigation::GROUP_LOANS,
        MemberNavigation::GROUP_SETTINGS,
    ]);
});

test('member resources use legacy navigation groups and sort order', function (string $class, ?string $group, int $sort) {
    expect($class::getNavigationGroup())->toBe($group)
        ->and($class::getNavigationSort())->toBe($sort);
})->with([
            'messages' => [MyMessageResource::class, null, MemberNavigation::SORT_MESSAGES],
            'contributions' => [MyContributionResource::class, MemberNavigation::GROUP_MY_FINANCE, MemberNavigation::SORT_CONTRIBUTIONS],
            'deposits' => [MyFundPostingResource::class, MemberNavigation::GROUP_MY_FINANCE, MemberNavigation::SORT_DEPOSITS],
            'statements' => [MyStatementResource::class, MemberNavigation::GROUP_MY_FINANCE, MemberNavigation::SORT_STATEMENTS],
            'dependents' => [MyDependentResource::class, MemberNavigation::GROUP_MY_FINANCE, MemberNavigation::SORT_DEPENDENTS],
            'accounts' => [MyAccountResource::class, MemberNavigation::GROUP_MY_FINANCE, MemberNavigation::SORT_ACCOUNTS],
            'loans' => [MyLoanResource::class, MemberNavigation::GROUP_LOANS, MemberNavigation::SORT_LOANS],
            'guaranteed loans' => [MyGuaranteedLoanResource::class, MemberNavigation::GROUP_LOANS, MemberNavigation::SORT_GUARANTEED_LOANS],
            'contribution settings' => [MyContributionSettingsPage::class, MemberNavigation::GROUP_SETTINGS, MemberNavigation::SORT_CONTRIBUTION_SETTINGS],
            'notification preferences' => [MyNotificationPreferencesPage::class, MemberNavigation::GROUP_SETTINGS, MemberNavigation::SORT_NOTIFICATION_PREFERENCES],
            'support' => [SupportPage::class, MemberNavigation::GROUP_SETTINGS, MemberNavigation::SORT_SUPPORT],
        ]);
