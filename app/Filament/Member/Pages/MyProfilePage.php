<?php

namespace App\Filament\Member\Pages;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\ImpersonationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class MyProfilePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.member.pages.my-profile';

    public static function getNavigationLabel(): string
    {
        return __('My profile');
    }

    public function getTitle(): string
    {
        return __('My profile');
    }

    public function mount(): void
    {
        $user = auth('tenant')->user();
        if ($user instanceof User) {
            auth('tenant')->setUser($user->fresh(['member']));
        }
    }

    protected function currentMember(): ?Member
    {
        $user = auth('tenant')->user();

        if (! $user instanceof User) {
            return null;
        }

        $member = $user->activeMember();

        return $member?->load(['user', 'parent', 'dependents.user']);
    }

    protected function getViewData(): array
    {
        $member = $this->currentMember();

        return [
            'user' => $member?->user,
            'member' => $member,
            'householdProfiles' => $member && $member->isParent()
                ? Member::query()
                    ->with('user')
                    ->where(function ($query) use ($member): void {
                        $query->whereKey($member->id)
                            ->orWhere('parent_member_id', $member->id);
                    })
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$member->id])
                    ->get()
                : collect(),
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('edit_profile')
                ->label(__('Edit profile'))
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => EditMyProfilePage::getUrl(panel: 'member'))
                ->color('primary'),
        ];

        if (session()->has('impersonator_user_id')) {
            $actions[] = Action::make('stop_impersonation')
                ->label(__('Return to parent portal'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->action(function (): void {
                    app(ImpersonationService::class)->stop();
                    $this->redirect(Filament::getPanel('member')->getUrl());
                });
        }

        return $actions;
    }
}
