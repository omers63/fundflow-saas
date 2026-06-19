<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Member\Support\MemberPortalViewModal;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Support\MemberFaq;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class CommunicationsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Help & FAQ';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_HELP;

    protected static ?string $slug = 'help';

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

    public function getTitle(): string
    {
        return __('Help & FAQ');
    }

    public function getSubheading(): ?string
    {
        return __('Messages, support requests, alerts, and frequently asked questions.');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['messages', 'requests', 'alerts', 'faq'], true)) {
            $this->activeTab = $tab;
        }
    }

    protected function getHeaderActions(): array
    {
        if ($this->activeTab !== 'requests') {
            return [];
        }

        return [
            MemberPortalViewModal::applyToForm(
                Action::make('submit_request')
                    ->label(__('Submit request'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->modalHeading(__('Submit support request'))
                    ->modalDescription(__('Send a message to fund administrators. They will be notified in the admin panel.'))
                    ->schema([
                        Select::make('category')
                            ->label(__('Category'))
                            ->options(SupportRequest::categoryOptions())
                            ->required()
                            ->native(false),
                        TextInput::make('subject')
                            ->label(__('Subject'))
                            ->required()
                            ->maxLength(150),
                        Textarea::make('message')
                            ->label(__('Message'))
                            ->required()
                            ->rows(5)
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data): void {
                        $user = auth('tenant')->user();
                        $member = CurrentMember::get();

                        if ($user === null) {
                            return;
                        }

                        $supportRequest = SupportRequest::query()->create([
                            'user_id' => $user->id,
                            'member_id' => $member?->id,
                            'category' => $data['category'],
                            'subject' => $data['subject'],
                            'message' => $data['message'],
                        ]);

                        $categoryLabel = SupportRequest::categoryLabel($data['category']);
                        $memberInfo = $member !== null
                            ? "{$user->name} (#{$member->member_number})"
                            : $user->name;

                        $body = __('Request #:id from :from', [
                            'id' => $supportRequest->id,
                            'from' => $memberInfo,
                        ])
                            ."\n".__('Category: :category', ['category' => $categoryLabel])
                            ."\n\n".$data['message'];

                        User::query()
                            ->where('is_admin', true)
                            ->each(function (User $admin) use ($data, $body, $supportRequest): void {
                                Notification::make()
                                    ->title(__('Support request #:id: :subject', [
                                        'id' => $supportRequest->id,
                                        'subject' => $data['subject'],
                                    ]))
                                    ->body($body)
                                    ->icon('heroicon-o-chat-bubble-left-right')
                                    ->iconColor('warning')
                                    ->sendToDatabase($admin);
                            });

                        Notification::make()
                            ->title(__('Request submitted'))
                            ->body(__('Fund administrators have been notified.'))
                            ->success()
                            ->send();
                    }),
            ),
        ];
    }

    public function messagesUrl(): string
    {
        return MyMessageResource::getUrl('index');
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
