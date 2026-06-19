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

class MyDependentResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'My dependents';

    protected static ?string $modelLabel = 'Dependent';

    protected static ?string $pluralModelLabel = 'My dependents';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_DEPENDENTS;

    public static function canAccess(): bool
    {
        $member = CurrentMember::get();

        return $member !== null && $member->isParent();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        $member = CurrentMember::get();

        return $member !== null && $member->dependents()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        $parentId = CurrentMember::id();

        return parent::getEloquentQuery()
            ->where('parent_member_id', $parentId)
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
}
