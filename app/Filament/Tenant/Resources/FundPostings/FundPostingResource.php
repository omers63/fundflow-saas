<?php

namespace App\Filament\Tenant\Resources\FundPostings;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Filament\Tenant\Resources\FundPostings\Pages\CreateFundPosting;
use App\Filament\Tenant\Resources\FundPostings\Pages\ListFundPostings;
use App\Filament\Tenant\Resources\FundPostings\Schemas\FundPostingForm;
use App\Filament\Tenant\Resources\FundPostings\Tables\FundPostingsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\FundPostingInsightsWidget;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class FundPostingResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundPosting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Deposits';

    protected static ?string $modelLabel = 'Deposit';

    protected static ?string $pluralModelLabel = 'Deposits';

    protected static ?int $navigationSort = TenantNavigation::SORT_DEPOSITS;

    public static function canCreate(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function form(Schema $schema): Schema
    {
        return FundPostingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundPostingsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) FundPosting::pending()->count() ?: null;
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

    public static function getPages(): array
    {
        return [
            'index' => ListFundPostings::route('/'),
            'create' => CreateFundPosting::route('/create'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(FundPostingInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );

        DatabaseNotificationsRefresh::dispatch($livewire);
    }
}
