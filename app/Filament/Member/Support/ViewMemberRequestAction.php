<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\MemberRequestViewSections;
use App\Models\Tenant\MemberRequest;
use Filament\Actions\ViewAction;

final class ViewMemberRequestAction
{
    public static function make(): ViewAction
    {
        return MemberPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (MemberRequest $record): string => MemberRequest::typeLabel($record->type))
                ->modalContent(fn (MemberRequest $record) => MemberPortalViewModal::content(
                    MemberRequestViewSections::forMember($record),
                )),
        );
    }
}
