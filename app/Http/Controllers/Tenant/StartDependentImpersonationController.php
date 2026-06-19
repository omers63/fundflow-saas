<?php

namespace App\Http\Controllers\Tenant;

use App\Filament\Member\Pages\MemberSettingsPage;
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

        if ($dependent->is_separated) {
            return redirect(MemberSettingsPage::getUrl(['tab' => 'profile'], panel: 'member'));
        }

        $dependentUser = $dependent->user;
        if (! $dependentUser instanceof User) {
            abort(403);
        }

        $memberPanel = Filament::getPanel('member');
        if ($memberPanel !== null && ! $dependentUser->canAccessPanel($memberPanel)) {
            return redirect(MyDependentResource::getUrl('index'));
        }

        app(ImpersonationService::class)->start($actor, $dependentUser, $dependent);

        return redirect($memberPanel?->getUrl() ?? '/member');
    }
}
