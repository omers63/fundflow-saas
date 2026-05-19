<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Services\Tenant\NotificationPreferenceService;
use App\Support\CommunicationSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

class MyNotificationPreferencesPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Notification preferences';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SETTINGS;

    protected static ?int $navigationSort = MemberNavigation::SORT_NOTIFICATION_PREFERENCES;

    protected static ?string $slug = 'notification-preferences';

    protected string $view = 'filament.member.pages.my-notification-preferences';

    /** @var array<string, list<string>> */
    public array $prefs = [];

    public ?string $savedAt = null;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Notification preferences');
    }

    public function getSubheading(): ?string
    {
        return __('Choose how you receive alerts for each category. Required channels cannot be disabled.');
    }

    public function mount(): void
    {
        $this->loadPreferences();
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
}
