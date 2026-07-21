<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\OpensFocusedLedgerTransaction;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionDescriptionColumn;
use App\Filament\Support\AccountTransactionLinkedSourceColumn;
use App\Filament\Support\AccountTransactionLinkedSourceFilter;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Filament\Tenant\Resources\Members\Concerns\SuppressesMemberWorkspaceTabBadges;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberTransactionsTabsRelationManager extends RelationManager
{
    use OpensFocusedLedgerTransaction;
    use SuppressesMemberWorkspaceTabBadges;
    use TranslatesRelationManagerTitle;

    protected string $view = 'filament.tenant.resources.members.relation-managers.member-transactions-tabs';

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Ledger';

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
                    ->with('account');
            })
            ->heading(match ($this->ledgerTab) {
                'fund' => UiLabelIcons::labeledHtml(__('Fund transactions'), UiLabelIcons::forKey('fund')),
                'loan' => UiLabelIcons::labeledHtml(__('Loan account transactions'), UiLabelIcons::forKey('loan')),
                default => UiLabelIcons::labeledHtml(__('Cash transactions'), UiLabelIcons::forKey('cash')),
            })
            ->columns([
                TextColumn::make('transacted_at')->dateTime()->sortable(),
                TextColumn::make('account_scope')
                    ->label(__('Scope'))
                    ->state(fn (Transaction $record): string => $record->account?->is_master
                        ? __('Master')
                        : __('Member'))
                    ->badge()
                    ->color(fn (Transaction $record): string => $record->account?->is_master ? 'primary' : 'gray')
                    ->searchable(false)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $transactionTable = $query->getModel()->getTable();
                        $accountTable = (new Account)->getTable();

                        return $query->orderBy(
                            Account::query()
                                ->select('is_master')
                                ->whereColumn($accountTable.'.id', $transactionTable.'.account_id')
                                ->limit(1),
                            $direction,
                        );
                    })
                    ->toggleable(),
                AccountTransactionAmountColumn::make(),
                AccountTransactionDescriptionColumn::make(),
                AccountTransactionLinkedSourceColumn::make(),
                TextColumn::make('id')
                    ->label(__('Txn #'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('balance_after')
                    ->label(__('Balance after'))
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_class')
                    ->label(__('Scope'))
                    ->options([
                        'member' => __('Member'),
                        'master' => __('Master'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'master' => $query->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('is_master', true)),
                            'member' => $query->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('is_master', false)),
                            default => $query,
                        };
                    }),
                SelectFilter::make('type')
                    ->options(AccountTransactionTypeFilter::options()),
                DateColumnRangeFilter::make('transacted_at', __('Date')),
                AccountTransactionLinkedSourceFilter::make(),
            ]);

        if (in_array($this->ledgerTab, ['cash', 'fund'], true)) {
            $account = $this->resolveMemberLedgerAccount($member);

            if ($account !== null) {
                $table = $table->headerActions(
                    AccountTransactionManualAdjustmentHeaderActions::make(
                        fn (): Account => $account,
                        function () use ($account): void {
                            $this->resetTable();
                            AccountDetailInsightsRefresh::dispatchLedgerChange((int) $account->id);
                        },
                    ),
                );
            }
        }

        return ViewAccountTransactionAction::configure($table)
            ->defaultSort('transacted_at', 'desc')
            ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions());
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'member_ledger_'.$this->ledgerTab;
    }

    private function resolveMemberLedgerAccount(Member $member): ?Account
    {
        $relation = $this->ledgerTab === 'fund' ? 'fundAccount' : 'cashAccount';
        $member->loadMissing($relation);
        $account = $member->{$relation};

        if ($account !== null) {
            return $account;
        }

        app(AccountingService::class)->createMemberAccounts($member);
        $member->load($relation);

        return $member->{$relation};
    }
}
