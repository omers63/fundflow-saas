<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MasterAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Tenant\Resources\BankAccounts\Tables\PendingOperationalClearanceTable;
use App\Models\Tenant\Account;
use App\Services\BankClearingMatchService;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PendingOperationalClearanceRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'pendingOperationalClearanceBankTransactions';

    protected static ?string $title = 'Pending bank match';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Account
            && $ownerRecord->is_master
            && BankClearingMatchService::masterAccountTypeSupportsPendingClearance($ownerRecord->type);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return PendingOperationalClearanceTable::configure(
            $table,
            showClearanceKindColumn: in_array($owner->type, ['cash', 'invest'], true),
        );
    }
}
