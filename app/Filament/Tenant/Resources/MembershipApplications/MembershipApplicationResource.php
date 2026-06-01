<?php

namespace App\Filament\Tenant\Resources\MembershipApplications;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\CreateMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\EditMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Filament\Tenant\Resources\MembershipApplications\Schemas\MembershipApplicationForm;
use App\Filament\Tenant\Resources\MembershipApplications\Tables\MembershipApplicationsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MembershipApplicationInsightsWidget;
use App\Models\Tenant\MembershipApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;

class MembershipApplicationResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MembershipApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_APPLICATIONS;

    protected static ?string $navigationLabel = 'Applications';

    public static function canCreate(): bool
    {
        return (bool) auth('tenant')->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipApplicationsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MembershipApplication::pending()->count();

        return $count > 0 ? (string) $count : null;
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

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipApplications::route('/'),
            'create' => CreateMembershipApplication::route('/create'),
            'edit' => EditMembershipApplication::route('/{record}/edit'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MembershipApplicationInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName(' . $targetName . ').forEach(w => w.$refresh()), 0)'
        );
    }
}
