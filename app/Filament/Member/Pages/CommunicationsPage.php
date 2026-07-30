<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Pages\Page;
use App\Support\MemberFaq;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class CommunicationsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_HELP;

    protected static ?string $slug = 'messages';

    protected string $view = 'filament.member.pages.communications';

    #[Url(as: 'tab', except: 'messages')]
    public string $activeTab = 'messages';

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MemberNavigation::unreadAdminMessageCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        if ($this->activeTab === 'requests') {
            $params = [];
            $section = request()->query('section');

            if (is_string($section) && in_array($section, ['support', 'membership'], true)) {
                $params['section'] = $section;
            }

            $this->redirect(RequestsPage::getUrl($params), navigate: true);
        }
    }

    public function getTitle(): string
    {
        return __('Messages');
    }

    public function getSubheading(): ?string
    {
        return match ($this->activeTab) {
            'alerts' => __('Past alerts and notifications delivered to your account.'),
            'faq' => __('Quick answers about contributions, loans, and account features.'),
            default => __('Inbox messages from fund administrators.'),
        };
    }

    public function setTab(string $tab): void
    {
        if ($tab === 'requests') {
            $this->redirect(RequestsPage::getUrl(), navigate: true);

            return;
        }

        if (in_array($tab, ['messages', 'alerts', 'faq'], true)) {
            $this->activeTab = $tab;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'faqItems' => MemberFaq::items(),
        ];
    }
}
