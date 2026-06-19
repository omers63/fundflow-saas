<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\MemberActivityFeedService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberActivityTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public string $filter = MemberActivityFeedService::FILTER_ALL;

    public function getHeading(): ?string
    {
        return __('Transactions');
    }

    protected function getTableQuery(): Builder
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return Transaction::query()->whereRaw('0 = 1');
        }

        return app(MemberActivityFeedService::class)
            ->applyFilter(
                app(MemberActivityFeedService::class)->baseQuery($member),
                $this->filter,
            )
            ->with('account');
    }

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure(
            $table
                ->heading(__('Transactions'))
                ->description(__('Credits and debits across your cash and fund accounts.'))
                ->columns([
                    TextColumn::make('transacted_at')
                        ->label(__('Date'))
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('account_label')
                        ->label(__('Account'))
                        ->state(fn (Transaction $record): string => $record->account?->memberFacingLabel() ?? '—')
                        ->badge()
                        ->color(fn (Transaction $record): string => match ($record->account?->type) {
                            'fund' => 'primary',
                            'cash' => 'success',
                            default => 'gray',
                        }),
                    TextColumn::make('description')
                        ->label(__('Description'))
                        ->state(fn (Transaction $record): string => $record->memberFacingDescription())
                        ->searchable(query: function (Builder $query, string $search): Builder {
                            return $query->where('description', 'like', '%'.$search.'%');
                        })
                        ->wrap(),
                    TextColumn::make('credit')
                        ->label(__('Credit'))
                        ->state(fn (Transaction $record): ?float => $record->type === 'credit' ? (float) $record->amount : null)
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->placeholder('—'),
                    TextColumn::make('debit')
                        ->label(__('Debit'))
                        ->state(fn (Transaction $record): ?float => $record->type === 'debit' ? (float) $record->amount : null)
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->placeholder('—'),
                    TextColumn::make('category')
                        ->label(__('Type'))
                        ->state(fn (Transaction $record): string => $record->memberActivityCategoryLabel())
                        ->badge()
                        ->color(fn (Transaction $record): string => $record->type === 'credit' ? 'success' : 'danger'),
                ])
                ->filters([
                    DateColumnRangeFilter::make('transacted_at', __('Date')),
                ])
                ->defaultSort('transacted_at', 'desc')
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            editable: false,
            memberPortal: true,
        );
    }
}
