<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Member\Support\SwitchHouseholdProfileAction;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\MemberMonthlyAllocationService;
use App\Services\Tenant\NotificationPreferenceService;
use App\Support\CommunicationSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class MemberSettingsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_SETTINGS;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.member.pages.member-settings';

    #[Url(as: 'tab', except: 'profile')]
    public string $activeTab = 'profile';

    public int $monthly_contribution_amount = 500;

    public bool $allocationChangeBlocked = false;

    public ?string $allocationChangeBlockedMessage = null;

    /** @var array<string, list<string>> */
    public array $prefs = [];

    public ?string $savedAt = null;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    public function getSubheading(): ?string
    {
        return __('Profile, contributions, notifications, and payout details.');
    }

    public function mount(): void
    {
        $user = auth('tenant')->user();

        if ($user instanceof User) {
            auth('tenant')->setUser($user->fresh(['member']));
        }

        $this->loadContributionSettings();
        $this->loadPreferences();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['profile', 'contributions', 'notifications', 'payout'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function loadContributionSettings(): void
    {
        $member = CurrentMember::get();
        $this->monthly_contribution_amount = (int) ($member?->monthly_contribution_amount ?? 500);

        if ($member === null) {
            return;
        }

        $allocations = app(MemberMonthlyAllocationService::class);
        $this->allocationChangeBlocked = ! $allocations->canChangeMonthlyContribution($member);
        $this->allocationChangeBlockedMessage = $this->allocationChangeBlocked
            ? $allocations->allocationChangeBlockedMessage($member)
            : null;
    }

    public function loadPreferences(): void
    {
        $userId = auth('tenant')->id();

        if ($userId === null) {
            return;
        }

        foreach (NotificationPreferenceService::CATEGORIES as $type => $meta) {
            $saved = MemberCommunicationPreference::channelsFor($userId, $type, $meta['defaults']);
            $this->prefs[$type] = array_values(array_unique(array_merge($meta['forced'], $saved)));
        }
    }

    public function save(): void
    {
        $this->savePreferences();
    }

    public function savePreferences(): void
    {
        $userId = auth('tenant')->id();

        if ($userId === null) {
            return;
        }

        foreach ($this->prefs as $type => $channels) {
            if (! isset(NotificationPreferenceService::CATEGORIES[$type])) {
                continue;
            }

            $meta = NotificationPreferenceService::CATEGORIES[$type];
            $clean = array_values(array_unique(array_merge(
                $meta['forced'],
                array_intersect((array) $channels, $meta['supported']),
            )));

            MemberCommunicationPreference::saveFor($userId, $type, $clean, $meta['forced']);
        }

        $this->savedAt = now()->toTimeString();

        Notification::make()
            ->title(__('Preferences saved'))
            ->body(__('Your notification preferences have been updated.'))
            ->success()
            ->send();
    }

    public function toggleChannel(string $type, string $channel): void
    {
        if (! isset(NotificationPreferenceService::CATEGORIES[$type])) {
            return;
        }

        $meta = NotificationPreferenceService::CATEGORIES[$type];

        if (in_array($channel, $meta['forced'], true)) {
            return;
        }

        if (! in_array($channel, $meta['supported'], true)) {
            return;
        }

        $current = $this->prefs[$type] ?? $meta['defaults'];

        if (in_array($channel, $current, true)) {
            $current = array_values(array_filter($current, fn (string $c): bool => $c !== $channel));
        } else {
            $current[] = $channel;
            $current = array_values(array_unique($current));
        }

        $this->prefs[$type] = $current;
    }

    public function isEnabled(string $type, string $channel): bool
    {
        return in_array($channel, $this->prefs[$type] ?? [], true);
    }

    public function isForced(string $type, string $channel): bool
    {
        $meta = NotificationPreferenceService::CATEGORIES[$type] ?? [];

        return in_array($channel, $meta['forced'] ?? [], true);
    }

    public function isSupported(string $type, string $channel): bool
    {
        $meta = NotificationPreferenceService::CATEGORIES[$type] ?? [];

        return in_array($channel, $meta['supported'] ?? [], true);
    }

    public function isSystemEnabled(string $channel): bool
    {
        return CommunicationSettings::channelEnabled($channel);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function categories(): array
    {
        return collect(NotificationPreferenceService::CATEGORIES)
            ->map(fn (array $meta): array => [
                ...$meta,
                'label' => __($meta['label']),
                'description' => __($meta['description']),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = $this->currentMember();

        return [
            'profileUser' => $member?->user,
            'profileMember' => $member,
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
            'payoutIban' => $this->resolvePayoutIban($member),
            'faqItems' => [],
        ];
    }

    protected function getHeaderActions(): array
    {
        if ($this->activeTab === 'contributions') {
            $actions = [
                Action::make('save_allocation')
                    ->label(__('Save allocation'))
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->disabled($this->allocationChangeBlocked)
                    ->tooltip($this->allocationChangeBlockedMessage)
                    ->fillForm(['monthly_contribution_amount' => $this->monthly_contribution_amount])
                    ->schema([
                        Select::make('monthly_contribution_amount')
                            ->label(__('Monthly contribution amount'))
                            ->options(Member::contributionAmountOptions())
                            ->required()
                            ->helperText(__('Choose your monthly contribution tier. Administrators are notified when you change it.')),
                    ])
                    ->action(function (array $data): void {
                        $member = CurrentMember::get();

                        if ($member === null) {
                            Notification::make()
                                ->title(__('Member record not found'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $newAmount = (int) $data['monthly_contribution_amount'];
                        $oldAmount = (int) $member->monthly_contribution_amount;

                        if (! Member::isValidContributionAmount($newAmount)) {
                            Notification::make()
                                ->title(__('Invalid amount selected'))
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($newAmount === $oldAmount) {
                            Notification::make()
                                ->title(__('No changes detected'))
                                ->info()
                                ->send();

                            return;
                        }

                        try {
                            app(MemberMonthlyAllocationService::class)->assertCanChangeMonthlyContribution($member);
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(__('Allocation cannot be changed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $member->update(['monthly_contribution_amount' => $newAmount]);

                        User::query()
                            ->where('is_admin', true)
                            ->each(function (User $admin) use ($member, $oldAmount, $newAmount): void {
                                Notification::make()
                                    ->title(__('Member allocation updated'))
                                    ->body(__('Member :name changed monthly contribution from :old to :new.', [
                                        'name' => $member->name,
                                        'old' => number_format($oldAmount),
                                        'new' => number_format($newAmount),
                                    ]))
                                    ->icon('heroicon-o-adjustments-horizontal')
                                    ->iconColor('info')
                                    ->sendToDatabase($admin);
                            });

                        Notification::make()
                            ->title(__('Allocation updated'))
                            ->body(__('Your monthly contribution amount has been saved.'))
                            ->success()
                            ->send();

                        $this->loadContributionSettings();
                    }),
            ];

            $member = CurrentMember::get();

            if ($member !== null && $member->dependents()->exists()) {
                $actions[] = Action::make('family_requests')
                    ->label(__('My dependents'))
                    ->icon('heroicon-o-users')
                    ->url(MyDependentResource::getUrl())
                    ->color('gray');
            }

            return $actions;
        }

        if ($this->activeTab === 'profile') {
            return [
                Action::make('edit_profile')
                    ->label(__('Edit profile'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (): string => EditMyProfilePage::getUrl(panel: 'member'))
                    ->color('primary'),
            ];
        }

        return [];
    }

    protected function currentMember(): ?Member
    {
        $user = auth('tenant')->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->activeMember()?->load(['user', 'parent', 'dependents.user']);
    }

    private function resolvePayoutIban(?Member $member): ?string
    {
        if ($member === null) {
            return null;
        }

        $iban = MembershipApplication::query()
            ->where('email', $member->email)
            ->whereNotNull('iban')
            ->latest('id')
            ->value('iban');

        return filled($iban) ? strtoupper((string) $iban) : null;
    }

    public function switchHouseholdProfileAction(): Action
    {
        return SwitchHouseholdProfileAction::make();
    }
}
