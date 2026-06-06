<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsTransactions\Pages;

use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsTransactions extends ListRecords
{
    protected static string $resource = SmsTransactionResource::class;
}
