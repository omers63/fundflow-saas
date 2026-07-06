<?php

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\BusinessDayTestingPage;
use App\Filament\Member\Pages\CashAccountPage;
use App\Filament\Member\Pages\CommunicationsPage;
use App\Filament\Member\Pages\FundAccountPage;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Pages\MemberActivityPage;
use App\Filament\Member\Pages\MemberSettingsPage;
use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Filament\Member\Pages\MyNotificationPreferencesPage;
use App\Filament\Member\Pages\SupportPage;
use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Filament\Member\Support\MemberNavigation;
use Tests\TestCase;

uses(TestCase::class);

test('member navigation group keys match prototype sidebar sections', function () {
    expect(MemberNavigation::groupKeys())->toBe([
        MemberNavigation::GROUP_MY_ACCOUNTS,
        MemberNavigation::GROUP_LOANS,
        MemberNavigation::GROUP_HISTORY,
        MemberNavigation::GROUP_SELF_SERVICE,
    ]);
});

test('member navigation group labels match prototype', function () {
    expect(MemberNavigation::groupLabel(MemberNavigation::GROUP_MY_ACCOUNTS))->toBe(__('My Accounts'))
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_LOANS))->toBe(__('Loans'))
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_HISTORY))->toBe(__('History'))
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_SELF_SERVICE))->toBe(__('Self-Service'));
});

test('member resources use navigation groups and sort order', function (string $class, ?string $group, int $sort) {
    expect($class::getNavigationGroup())->toBe($group)
        ->and($class::getNavigationSort())->toBe($sort);
})->with([
    'cash account' => [CashAccountPage::class, MemberNavigation::GROUP_MY_ACCOUNTS, MemberNavigation::SORT_CASH_ACCOUNT],
    'fund account' => [FundAccountPage::class, MemberNavigation::GROUP_MY_ACCOUNTS, MemberNavigation::SORT_FUND_ACCOUNT],
    'my loans' => [MyLoanResource::class, MemberNavigation::GROUP_LOANS, MemberNavigation::SORT_LOANS],
    'guaranteed loans' => [MyGuaranteedLoanResource::class, MemberNavigation::GROUP_LOANS, MemberNavigation::SORT_GUARANTEED_LOANS],
    'loan calculator' => [LoanCalculatorPage::class, MemberNavigation::GROUP_LOANS, MemberNavigation::SORT_LOAN_CALCULATOR],
    'contributions' => [MyContributionResource::class, MemberNavigation::GROUP_HISTORY, MemberNavigation::SORT_CONTRIBUTIONS],
    'transactions' => [MemberActivityPage::class, MemberNavigation::GROUP_HISTORY, MemberNavigation::SORT_ACTIVITY],
    'cash out' => [MyCashOutRequestResource::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_CASH_OUTS],
    'statements' => [MyStatementResource::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_STATEMENTS],
    'deposits' => [MyFundPostingResource::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_DEPOSITS],
    'dependents' => [MyDependentResource::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_DEPENDENTS],
    'settings' => [MemberSettingsPage::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_SETTINGS],
    'help' => [CommunicationsPage::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_HELP],
    'accounts legacy' => [MyAccountResource::class, MemberNavigation::GROUP_MY_ACCOUNTS, MemberNavigation::SORT_ACCOUNTS],
    'contribution settings legacy' => [MyContributionSettingsPage::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_CONTRIBUTION_SETTINGS],
    'notification preferences legacy' => [MyNotificationPreferencesPage::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_NOTIFICATION_PREFERENCES],
    'support legacy' => [SupportPage::class, MemberNavigation::GROUP_SELF_SERVICE, MemberNavigation::SORT_SUPPORT],
]);

test('business day testing page is hidden from member navigation', function () {
    expect(BusinessDayTestingPage::shouldRegisterNavigation())->toBeFalse();
});

test('apply for loan page is hidden from member navigation', function () {
    expect(ApplyForLoan::shouldRegisterNavigation())->toBeFalse();
});

test('legacy member resources remain hidden from navigation', function (string $class) {
    expect($class::shouldRegisterNavigation())->toBeFalse();
})->with([
    MyAccountResource::class,
    MyMessageResource::class,
    SupportPage::class,
    MyContributionSettingsPage::class,
    MyNotificationPreferencesPage::class,
    BusinessDayTestingPage::class,
]);

test('restored member features register in navigation', function (string $class) {
    expect($class::shouldRegisterNavigation())->toBeTrue();
})->with([
    MyFundPostingResource::class,
    LoanCalculatorPage::class,
]);
