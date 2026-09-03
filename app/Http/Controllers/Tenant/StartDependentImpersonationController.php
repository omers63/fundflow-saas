<?php

namespace App\Http\Controllers\Tenant;

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\ImpersonationService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

class StartDependentImpersonationController extends Controller
{
    public function __invoke(Member $dependent): RedirectResponse
    {
        $guardName = Filament::getPanel('member')?->getAuthGuard() ?? 'tenant';
        $actor = auth()->guard($guardName)->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $parentMember = Member::query()
            ->where('user_id', $actor->id)
            ->whereNull('parent_member_id')
            ->first();

        if (
            $parentMember === null
            || (int) $dependent->parent_member_id !== (int) $parentMember->id
        ) {
            abort(403);
        }

        $dependentUser = $dependent->user;
        if (! $dependentUser instanceof User) {
            abort(403);
        }

        $memberPanel = Filament::getPanel('member');
        $impersonation = app(ImpersonationService::class);
        $adminOverride = $impersonation->isAdminImpersonatingUser($actor);

        // Explicit panel: this route can run after admin→parent impersonation while
        // Filament's current panel is still tenant/admin (not member).
        $dependentsIndexUrl = MyDependentResource::getUrl('index', panel: 'member');

        if (
            $memberPanel !== null
            && ! $dependentUser->canAccessPanel($memberPanel)
            && ! $adminOverride
        ) {
            return redirect($dependentsIndexUrl);
        }

        $returnUrl = url()->previous($dependentsIndexUrl);

        $impersonation->start(
            $actor,
            $dependentUser,
            $dependent,
            ImpersonationService::SOURCE_MEMBER_DEPENDENTS,
            $returnUrl,
        );

        return redirect($memberPanel?->getUrl() ?? '/member');
    }
}
