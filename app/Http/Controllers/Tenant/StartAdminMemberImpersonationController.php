<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\ImpersonationService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

class StartAdminMemberImpersonationController extends Controller
{
    public function __invoke(Member $member): RedirectResponse
    {
        $guardName = Filament::getPanel('tenant')?->getAuthGuard() ?? 'tenant';
        $actor = auth()->guard($guardName)->user();

        if (! $actor instanceof User || ! $actor->is_admin) {
            abort(403);
        }

        $impersonation = app(ImpersonationService::class);

        if (! $impersonation->canAdminImpersonate($member)) {
            return redirect(MemberResource::getUrl('view', ['record' => $member], panel: 'tenant'))
                ->with('error', __('This member cannot be impersonated (no portal login).'));
        }

        $memberUser = $member->user;
        assert($memberUser instanceof User);

        $returnUrl = MemberResource::getUrl('view', ['record' => $member], panel: 'tenant');

        $impersonation->start(
            $actor,
            $memberUser,
            $member,
            ImpersonationService::SOURCE_ADMIN,
            $returnUrl,
        );

        return redirect(Filament::getPanel('member')?->getUrl() ?? '/member');
    }
}
