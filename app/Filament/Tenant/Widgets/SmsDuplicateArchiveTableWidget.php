<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use App\Filament\Tenant\Resources\SmsTransactions\Tables\SmsTransactionsTable;
use App\Models\Tenant\SmsTransaction;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SmsDuplicateArchiveTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return SmsTransactionResource::getEloquentQuery()
            ->where('is_duplicate', true)
            ->with(['member', 'importSession']);
    }

    public function table(Table $table): Table
    {
        return SmsTransactionsTable::configure($table, embedInBankWorkspace: true)
            ->recordUrl(fn (SmsTransaction $record): string => SmsTransactionResource::getUrl('view', ['record' => $record]));
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
