<?php

namespace App\Filament\Member\Resources\MyAccounts\Tables;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyLoans\Tables\MyLoansTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyMemberAccountsLoansTable
{
    public static function configure(Table $table): Table
    {
        return MyLoansTable::configure($table)
            ->recordUrl(fn (Model $record): string => MyLoanResource::getUrl('view', ['record' => $record]));
    }
}
