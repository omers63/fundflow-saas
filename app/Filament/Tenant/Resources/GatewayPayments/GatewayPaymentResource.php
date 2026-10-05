<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\GatewayPayments;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\GatewayPayments\Pages\ManageGatewayPayments;
use App\Filament\Tenant\Resources\GatewayPayments\Tables\GatewayPaymentsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\GatewayPayment;
use App\Support\Billing\TenantFeatureGate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GatewayPaymentResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = GatewayPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Gateway payments';

    protected static ?string $modelLabel = 'Gateway payment';

    protected static ?string $pluralModelLabel = 'Gateway payments';

    protected static ?int $navigationSort = TenantNavigation::SORT_DEPOSITS + 1;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true
            && app(TenantFeatureGate::class)->allows(TenantFeatureGate::FEATURE_GATEWAY);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return GatewayPaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGatewayPayments::route('/'),
        ];
    }
}
