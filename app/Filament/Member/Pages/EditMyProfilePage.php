<?php

namespace App\Filament\Member\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * @deprecated Use {@see MemberSettingsPage} account tab. Route redirects to /member/settings?tab=profile.
 */
class EditMyProfilePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $slug = 'edit-profile';

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.member.pages.edit-my-profile';

    public function mount(): void
    {
        $this->redirect(MemberSettingsPage::getUrl(['tab' => 'profile'], panel: 'member'));
    }
}
