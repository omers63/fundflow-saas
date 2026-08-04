<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundOutRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Filament\Tenant\Resources\FundOutRequests\Pages\CreateFundOutRequest;
use App\Filament\Tenant\Resources\FundOutRequests\Pages\ListFundOutRequests;
use App\Filament\Tenant\Resources\FundOutRequests\Schemas\FundOutRequestForm;
use App\Filament\Tenant\Resources\FundOutRequests\Tables\FundOutRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Component;
use UnitEnum;

class FundOutRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundOutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightCircle;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Fund outs';

    protected static ?string $modelLabel = 'Fund out';

    protected static ?string $pluralModelLabel = 'Fund outs';

    protected static ?int $navigationSort = TenantNavigation::SORT_FUND_OUTS;

    public static function canCreate(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function form(Schema $schema): Schema
    {
        return FundOutRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundOutRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! DatabaseSchema::hasTable('fund_out_requests')) {
            return null;
        }

        return (string) FundOutRequest::pending()->count() ?: null;
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

        return static::getUrl('index', $parameters, panel: 'tenant');
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

        DatabaseNotificationsRefresh::dispatch($livewire);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundOutRequests::route('/'),
            'create' => CreateFundOutRequest::route('/create'),
        ];
    }
}
