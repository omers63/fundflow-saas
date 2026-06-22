<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\BankAccounts\Tables\BankTransactionsTable;
use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class BankClosedStatementLinesTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return app(BankClearingMatchService::class)
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->whereIn('status', ['posted', 'duplicate', 'ignored']);
    }

    public function table(Table $table): Table
    {
        return BankTransactionsTable::configure(
            $table,
            includeImportHeaderAction: false,
            auditMode: true,
        );
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
