<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\MemberRequestViewSections;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Models\Tenant\MemberRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;

final class ViewMemberRequestAction
{
    public static function make(): ViewAction
    {
        /** @var ViewAction $action */
        $action = TenantPortalViewModal::apply(
            ViewAction::make()
                ->label(__('View'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (MemberRequest $record): string => MemberRequest::typeLabel($record->type))
                ->modalContent(fn (MemberRequest $record) => TenantPortalViewModal::content(
                    MemberRequestViewSections::forAdmin($record),
                ))
                ->extraModalFooterActions(fn (MemberRequest $record): array => [
                    Action::make('openFullPage')
                        ->label(__('Review & decide'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(MemberRequestResource::getUrl('view', ['record' => $record]))
                        ->color('primary'),
                ]),
        );

        return $action;
    }
}
