<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Services\MemberAccountExportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

final class MemberAccountsListTableHeaderActions
{
    /**
     * @return list<Action|CreateAction>
     */
    public static function accounts(): array
    {
        $accountType = match (AccountResource::resolveListMemberAccountsTab()) {
            'cash' => 'cash',
            'fund' => 'fund',
            default => null,
        };

        return [
            MemberListTableHeaderActions::importMembersAction(),
            self::exportAccountsAction($accountType),
            CreateAction::make()
                ->label(__('New member'))
                ->icon('heroicon-o-plus-circle')
                ->url(MemberResource::getUrl('create'))
                ->visible(fn (): bool => MemberResource::canCreate()),
        ];
    }

    /**
     * @return list<Action|CreateAction>
     */
    public static function loans(): array
    {
        return LoanListTableHeaderActions::portfolio();
    }

    public static function exportAccountsAction(?string $accountType): Action
    {
        return Action::make('exportAccounts')
            ->label(match ($accountType) {
                'cash' => __('Export cash accounts'),
                'fund' => __('Export fund accounts'),
                default => __('Export accounts'),
            })
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->action(fn (): mixed => app(MemberAccountExportService::class)->downloadCsv($accountType));
    }
}
