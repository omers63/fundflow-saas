<?php

namespace App\Filament\Tenant\Resources\Accounts\Tables;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Tables\LoansTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MemberAccountsLoansTable
{
    public static function configure(Table $table): Table
    {
        return LoansTable::configure($table)
            ->recordUrl(fn (Model $record): string => LoanResource::getUrl('edit', ['record' => $record]));
    }
}
