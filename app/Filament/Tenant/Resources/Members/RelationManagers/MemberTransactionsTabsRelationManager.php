<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberTransactionsTabsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected string $view = 'filament.tenant.resources.members.relation-managers.member-transactions-tabs';

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction history';

    public string $ledgerTab = 'cash';

    public function setLedgerTab(string $tab): void
    {
        if (! in_array($tab, ['cash', 'fund', 'loan'], true)) {
            return;
        }

        $this->ledgerTab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $member = $this->getOwnerRecord();
        assert($member instanceof Member);

        $table = $table
            ->modifyQueryUsing(function (Builder $query) use ($member): Builder {
                return $query
                    ->where('member_id', $member->id)
                    ->whereHas('account', fn (Builder $q): Builder => $q->where('type', $this->ledgerTab))
                    ->with('account')
                    ->latest('transacted_at');
            })
            ->heading(match ($this->ledgerTab) {
                'fund' => UiLabelIcons::labeledHtml(__('Fund transactions'), UiLabelIcons::forKey('fund')),
                'loan' => UiLabelIcons::labeledHtml(__('Loan account transactions'), UiLabelIcons::forKey('loan')),
                default => UiLabelIcons::labeledHtml(__('Cash transactions'), UiLabelIcons::forKey('cash')),
            })
            ->columns([
                TextColumn::make('transacted_at')->dateTime()->sortable(),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('balance_after')
                    ->label(__('Balance after'))
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
            ]);

        if ($this->ledgerTab === 'cash') {
            $table = $table->headerActions(
                AccountTransactionManualAdjustmentHeaderActions::make(
                    fn (): Account => $member->cashAccount,
                    fn (): mixed => $this->resetTable(),
                ),
            );
        }

        return ViewAccountTransactionAction::configure($table)
            ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions());
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'member_ledger_'.$this->ledgerTab;
    }
}
