<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

class DisbursementsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Disbursements';

    protected static ?int $navigationSort = TenantNavigation::SORT_DISBURSEMENTS;

    protected static ?string $slug = 'disbursements';

    protected static ?string $title = 'Disbursements';

    protected string $view = 'filament.tenant.pages.disbursements';

    /** @var 'pending'|'partial'|'complete' */
    #[Url(as: 'tab')]
    public string $disbursementTab = 'pending';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Disbursements');
    }

    public function getSubheading(): ?string
    {
        return __('Post approved loan tranches and track remaining disbursement amounts.');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Loan::query()->readyToDisburse()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function setDisbursementTab(string $tab): void
    {
        if (! in_array($tab, ['pending', 'partial', 'complete'], true)) {
            return;
        }

        if ($this->disbursementTab === $tab) {
            return;
        }

        $this->disbursementTab = $tab;
        $this->resetTable();
    }

    /**
     * @return array<string, int|float|string>
     */
    public function summaryStats(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $pending = Loan::query()->readyToDisburse()->get(['amount_approved', 'amount_requested', 'amount_disbursed']);
        $committed = $pending->sum(fn (Loan $loan): float => (float) ($loan->amount_approved ?? $loan->amount_requested ?? 0));
        $disbursed = $pending->sum(fn (Loan $loan): float => (float) ($loan->amount_disbursed ?? 0));
        $remaining = max(0, $committed - $disbursed);

        return [
            'pending_count' => $pending->count(),
            'committed' => MoneyDisplay::format($committed, $currency) ?? '',
            'disbursed' => MoneyDisplay::format($disbursed, $currency) ?? '',
            'remaining' => MoneyDisplay::format($remaining, $currency) ?? '',
        ];
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(fn (): Builder => $this->disbursementQuery())
            ->headerActions([
                LoanFilamentActions::newDisbursementHeaderAction(),
            ])
            ->columns([
                MemberTableColumns::relationNumberFor(
                    memberNumberColumn: 'member.member_number',
                    memberIdColumn: 'loans.member_id',
                    label: __('Member #'),
                )->url(fn (Loan $record): ?string => $record->member
                    ? MemberTableColumns::memberRecordEditUrl($record->member)
                    : null),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount_approved')
                    ->label(__('Approved'))
                    ->money($currency)
                    ->placeholder('—'),
                TextColumn::make('amount_disbursed')
                    ->label(__('Disbursed'))
                    ->money($currency),
                TextColumn::make('remaining_to_disburse')
                    ->label(__('Remaining'))
                    ->state(fn (Loan $record): float => $record->remainingToDisburse())
                    ->money($currency)
                    ->color('warning')
                    ->searchable(false)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';
                        $table = $query->getModel()->getTable();

                        return $query->orderByRaw(
                            "GREATEST(0, COALESCE({$table}.amount_approved, 0) - COALESCE({$table}.amount_disbursed, 0)) {$dir}",
                        );
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => Loan::statusColor($state)),
                TextColumn::make('approved_at')
                    ->label(__('Approved'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('fund_tier_id')
                    ->label(__('Fund tier'))
                    ->relationship('fundTier', 'label'),
            ])
            ->recordUrl(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                LoanFilamentActions::disburse()->button(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('approved_at', 'desc')
            ->paginated([10, 25, 50]), TableGrouping::loanDisbursements());
    }

    protected function disbursementQuery(): Builder
    {
        return match ($this->disbursementTab) {
            'partial' => Loan::query()
                ->where('status', 'partially_disbursed')
                ->whereRaw('COALESCE(amount_disbursed, 0) < COALESCE(amount_approved, amount_requested, 0)'),
            'complete' => Loan::query()
                ->whereIn('status', ['active', 'partially_disbursed'])
                ->whereRaw('COALESCE(amount_disbursed, 0) >= COALESCE(amount_approved, amount_requested, 0) - 0.01'),
            default => Loan::query()->readyToDisburse(),
        };
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-disbursements'];
    }
}
