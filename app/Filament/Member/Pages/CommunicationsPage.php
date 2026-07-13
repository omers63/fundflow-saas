<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\SupportRequest;
use App\Support\MemberFaq;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Pages\Page;
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

    #[Url(as: 'section', except: 'support')]
    public string $requestsSection = 'support';

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

    public function getTitle(): string
    {
        return __('Messages');
    }

    public function getSubheading(): ?string
    {
        return match ($this->activeTab) {
            'requests' => match ($this->requestsSection) {
                'membership' => __('Freeze, withdraw, or change your membership status.'),
                default => __('Message fund administrators and track responses.'),
            },
            'alerts' => __('Past alerts and notifications delivered to your account.'),
            'faq' => __('Quick answers about contributions, loans, and account features.'),
            default => __('Inbox messages from fund administrators.'),
        };
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['messages', 'requests', 'alerts', 'faq'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function setRequestsSection(string $section): void
    {
        if (in_array($section, ['support', 'membership'], true)) {
            $this->requestsSection = $section;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $user = auth('tenant')->user();

        $openSupportCount = 0;
        $pendingMembershipCount = 0;

        if ($user !== null) {
            $openSupportCount = SupportRequest::query()
                ->where(function ($query) use ($user, $member): void {
                    $query->where('user_id', $user->id);

                    if ($member !== null) {
                        $query->orWhere('member_id', $member->id);
                    }
                })
                ->whereIn('status', [SupportRequest::STATUS_OPEN, SupportRequest::STATUS_IN_PROGRESS])
                ->count();
        }

        if ($member !== null) {
            $pendingMembershipCount = MemberRequest::query()
                ->where('requester_member_id', $member->id)
                ->where('status', MemberRequest::STATUS_PENDING)
                ->whereIn('type', [
                    MemberRequest::TYPE_FREEZE_MEMBERSHIP,
                    MemberRequest::TYPE_UNFREEZE_MEMBERSHIP,
                    MemberRequest::TYPE_WITHDRAW_MEMBERSHIP,
                    MemberRequest::TYPE_REQUEST_INDEPENDENCE,
                ])
                ->count();
        }

        return [
            'faqItems' => MemberFaq::items(),
            'requestsSection' => $this->requestsSection,
            'openSupportCount' => $openSupportCount,
            'pendingMembershipCount' => $pendingMembershipCount,
            'showDependentsLink' => $member !== null,
            'dependentsUrl' => MyDependentResource::getUrl('index'),
        ];
    }
}
