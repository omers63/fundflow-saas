<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\CreateSmsImportTemplate;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\EditSmsImportTemplate;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\ListSmsImportTemplates;
use App\Filament\Tenant\Resources\SmsImportTemplates\Schemas\SmsImportTemplateForm;
use App\Filament\Tenant\Resources\SmsImportTemplates\Tables\SmsImportTemplatesTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SmsImportTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use UnitEnum;

class SmsImportTemplateResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = SmsImportTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'SMS templates';

    protected static ?string $modelLabel = 'SMS template';

    protected static ?string $pluralModelLabel = 'SMS templates';

    protected static ?string $slug = 'sms-import-templates';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return SmsImportTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsImportTemplatesTable::configure(
            $table,
            includeCreateHeaderAction: ! (Livewire::current() instanceof ListSmsImportTemplates),
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsImportTemplates::route('/'),
            'create' => CreateSmsImportTemplate::route('/create'),
            'edit' => EditSmsImportTemplate::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withTrashed();
    }
}
