<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Support\CommunicationsTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Legacy `/admin/messages` route — redirects into the Communications workspace Inbox tab.
 */
class MessagesInboxPage extends Page
{
    use HidesFromTenantSidebar;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?int $navigationSort = TenantNavigation::SORT_MESSAGES;

    protected static ?string $slug = 'messages';

    protected string $view = 'filament.tenant.pages.messages-inbox';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function getNavigationBadge(): ?string
    {
        return CommunicationsWorkspacePage::getNavigationBadge();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('Messages inbox');
    }

    public function mount(): void
    {
        $this->redirect(CommunicationsWorkspacePage::getUrl(
            ['sideTab' => CommunicationsTabRegistry::TAB_INBOX],
            panel: 'tenant',
        ));
    }
}
