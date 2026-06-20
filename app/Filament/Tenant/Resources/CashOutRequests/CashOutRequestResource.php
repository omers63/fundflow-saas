<?php

namespace App\Filament\Tenant\Resources\CashOutRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\CreateCashOutRequest;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\ListCashOutRequests;
use App\Filament\Tenant\Resources\CashOutRequests\Schemas\CashOutRequestForm;
use App\Filament\Tenant\Resources\CashOutRequests\Tables\CashOutRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\CashOutRequestInsightsWidget;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class CashOutRequestResource extends Resource
{
    use HidesFromTenantSidebar;
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = CashOutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Cash outs';

    protected static ?string $modelLabel = 'Cash out';

    protected static ?string $pluralModelLabel = 'Cash outs';

    protected static ?int $navigationSort = TenantNavigation::SORT_CASH_OUTS;

    public static function canCreate(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function form(Schema $schema): Schema
    {
        return CashOutRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashOutRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) CashOutRequest::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(array $filters = []): string
    {
        $parameters = [];

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function memberFilter(int|Member $member): array
    {
        $memberId = $member instanceof Member ? $member->getKey() : $member;

        return [
            'member_id' => [
                'value' => (string) $memberId,
            ],
        ];
    }

    public static function indexUrlForMember(int|Member $member, ?string $status = null): string
    {
        $filters = static::memberFilter($member);

        if ($status !== null) {
            $filters['status'] = ['value' => $status];
        }

        return static::listUrl($filters);
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(CashOutRequestInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );

        DatabaseNotificationsRefresh::dispatch($livewire);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashOutRequests::route('/'),
            'create' => CreateCashOutRequest::route('/create'),
        ];
    }
}
