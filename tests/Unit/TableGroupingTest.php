<?php

use App\Filament\Support\TableGrouping;
use Filament\Tables\Contracts\HasTable;
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
]);

it('omits member group on member-scoped loan and contribution presets', function (): void {
    expect(TableGrouping::loans(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::contributions(includeMember: false))->toHaveCount(2)
        ->and(TableGrouping::fundPostings(includeMember: false))->toHaveCount(2);
});

it('apply returns a table with grouping configured', function (): void {
    $table = Table::make($this->createMock(HasTable::class));

    $result = TableGrouping::apply($table, TableGrouping::accountTransactions());

    expect($result)->toBeInstanceOf(Table::class)
        ->and($result->getGroups())->not->toBeEmpty();
});
