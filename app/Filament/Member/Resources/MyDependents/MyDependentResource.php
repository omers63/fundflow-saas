<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyDependents\Pages\ListMyDependents;
use App\Filament\Member\Resources\MyDependents\Tables\MyDependentsTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\Member;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class MyDependentResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Dependents';

    protected static ?string $modelLabel = 'Dependent';

    protected static ?string $pluralModelLabel = 'Dependents';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_DEPENDENTS;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $member = CurrentMember::get();

        if ($member === null || !$member->isParent()) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('parent_member_id', $member->id)
            ->with(['user', 'cashAccount', 'fundAccount']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MyDependentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyDependents::route('/'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        if ($livewire instanceof ListMyDependents) {
            $livewire->refreshDependentsInsights();

            return;
        }

        $livewire->dispatch('refresh-member-dependents-insights');
    }
}
