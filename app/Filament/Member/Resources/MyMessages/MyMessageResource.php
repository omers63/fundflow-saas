<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyMessages\Pages\ListMyMessages;
use App\Filament\Member\Resources\MyMessages\Pages\ViewMyMessage;
use App\Filament\Member\Resources\MyMessages\Tables\MyMessagesTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\User;
use App\Services\Tenant\DirectMessagingService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyMessageResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = DirectMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $modelLabel = 'Message';

    protected static ?string $pluralModelLabel = 'Messages';

    protected static ?int $navigationSort = MemberNavigation::SORT_MESSAGES;

    public static function getNavigationBadge(): ?string
    {
        $userId = auth('tenant')->id();

        if ($userId === null) {
            return null;
        }

        $count = DirectMessage::query()
            ->where('to_user_id', $userId)
            ->whereNull('read_at')
            ->whereHas('sender', fn (Builder $q) => $q->where('is_admin', true))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $memberUserId = auth('tenant')->id();

        return parent::getEloquentQuery()
            ->root()
            ->where(function (Builder $query) use ($memberUserId): void {
                $query->where(function (Builder $q) use ($memberUserId): void {
                    $q->where('from_user_id', $memberUserId)
                        ->whereHas('recipient', fn (Builder $r) => $r->where('is_admin', true));
                })->orWhere(function (Builder $q) use ($memberUserId): void {
                    $q->where('to_user_id', $memberUserId)
                        ->whereHas('sender', fn (Builder $s) => $s->where('is_admin', true));
                });
            })
            ->with(['sender', 'recipient']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MyMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyMessages::route('/'),
            'view' => ViewMyMessage::route('/{record}'),
        ];
    }

    public static function resolveAdminRecipient(): ?User
    {
        $memberUserId = auth('tenant')->id();

        if ($memberUserId === null) {
            return null;
        }

        return app(DirectMessagingService::class)->resolveAdminRecipientForMember((int) $memberUserId);
    }
}
