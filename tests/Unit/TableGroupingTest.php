<?php

use App\Filament\Support\TableGrouping;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Tests\TestCase;

uses(TestCase::class);

it('exposes expected group counts for ledger and listing presets', function (string $preset, int $count): void {
    expect(TableGrouping::{$preset}())->toHaveCount($count);
})->with([
    ['accountTransactions', 3],
    ['bankTransactions', 3],
    ['members', 3],
    ['loans', 3],
    ['contributions', 3],
    ['fundPostings', 3],
    ['membershipApplications', 3],
    ['loanRepayments', 2],
    ['bankStatements', 3],
    ['fundAuditLogs', 4],
    ['reconciliationExceptions', 4],
    ['loanEligibilityOverrides', 3],
    ['monthlyStatements', 3],
    ['loanInstallments', 2],
    ['directMessages', 2],
    ['configurationTiers', 1],
    ['systemJobRuns', 3],
    ['systemJobCatalog', 2],
    ['loanQueue', 3],
    ['delinquencyContributionArrears', 3],
    ['delinquencyGuarantorLoans', 3],
    ['centralTenants', 3],
    ['centralPlans', 2],
    ['centralInvoices', 3],
    ['centralSubscriptions', 2],
]);

it('omits member group on member-scoped loan and contribution presets', function (): void {
    expect(TableGrouping::loans(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::contributions(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::fundPostings(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::monthlyStatements(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::loanInstallments(includeLoanMember: true))->toHaveCount(3);
});

it('apply returns a table with grouping configured and collapsible groups', function (): void {
    $table = Table::make($this->createMock(HasTable::class));

    $result = TableGrouping::apply($table, TableGrouping::accountTransactions());

    expect($result)->toBeInstanceOf(Table::class)
        ->and($result->getGroups())->not->toBeEmpty();

    foreach ($result->getGroups() as $group) {
        expect($group)->toBeInstanceOf(Group::class)
            ->and($group->isCollapsible())->toBeTrue();
    }
});

it('collapsible helper marks every group collapsible', function (): void {
    $groups = TableGrouping::collapsible(TableGrouping::members());

    foreach ($groups as $group) {
        expect($group->isCollapsible())->toBeTrue();
    }
});
