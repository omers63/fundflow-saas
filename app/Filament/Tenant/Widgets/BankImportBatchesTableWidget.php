<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankStatementsTable;
use App\Models\Tenant\BankStatement;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class BankImportBatchesTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return BankAccountsResource::getEloquentQuery();
    }

    public function table(Table $table): Table
    {
        return BankStatementsTable::configure($table)
            ->recordUrl(fn (BankStatement $record): string => BankAccountsResource::getUrl('view', ['record' => $record]));
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
