<?php

declare(strict_types=1);

use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Support\ContributionListTableHeaderActions;
use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Support\MemberListTableHeaderActions;
use App\Filament\Support\TableHeaderIconAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('table header icon action applies icon button and tooltip from label', function () {
    $action = TableHeaderIconAction::apply(
        Action::make('demo')
            ->label(__('Import'))
            ->icon('heroicon-o-arrow-up-tray'),
    );

    expect($action->isIconButton())->toBeTrue()
        ->and($action->getTooltip())->toBe(__('Import'));
});

test('member contribution and bank import export new actions are icon only', function () {
    foreach ([
        MemberListTableHeaderActions::importMembersAction(),
        MemberListTableHeaderActions::exportMembersAction(),
        ContributionListTableHeaderActions::importContributionsAction(),
        ContributionListTableHeaderActions::exportContributionsAction(),
        BankWorkspaceImportTableHeaderActions::bankStatementImportAction(),
        LoanListTableHeaderActions::createLoanAction(),
    ] as $action) {
        expect($action->isIconButton())->toBeTrue()
            ->and($action->getTooltip())->not->toBeEmpty();
    }

    $portfolio = LoanListTableHeaderActions::portfolioGroup();

    expect($portfolio)->toBeInstanceOf(ActionGroup::class)
        ->and($portfolio->isIconButton())->toBeTrue()
        ->and($portfolio->getTooltip())->toBe(__('Portfolio'));
});
