<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Setting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationPreferenceService;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DependentAllocationChangedNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public DependentAllocationChange $change,
        public string $role = 'dependent',
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return match ($this->role) {
            'dependent' => NotificationPreferenceService::resolve(
                $notifiable,
                NotificationPreferenceService::ALLOCATIONS,
                [
                    NotificationPreferenceService::CH_IN_APP,
                    NotificationPreferenceService::CH_PUSH,
                    NotificationPreferenceService::CH_EMAIL,
                    NotificationPreferenceService::CH_SMS,
                    NotificationPreferenceService::CH_WHATSAPP,
                ],
            ),
            'parent' => NotificationPreferenceService::resolve(
                $notifiable,
                NotificationPreferenceService::ALLOCATIONS,
                [
                    NotificationPreferenceService::CH_IN_APP,
                    NotificationPreferenceService::CH_PUSH,
                    NotificationPreferenceService::CH_EMAIL,
                ],
            ),
            default => ['database'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'dependent_allocation_change_id' => $this->change->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon($this->icon())
            ->iconColor($this->iconColor());

        $url = $this->actionUrl();
        if ($url !== null) {
            $notification->actions([
                Action::make('view')
                    ->label($this->actionLabel())
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    protected function title(): string
    {
        $this->change->loadMissing(['dependent', 'parent']);

        $dependentName = (string) ($this->change->dependent?->name ?? __('Dependent'));
        $parentName = (string) ($this->change->parent?->name ?? __('Parent'));
        $direction = $this->change->isIncrease() ? '↑' : '↓';

        return match ($this->role) {
            'dependent' => __('Monthly allocation updated :direction', ['direction' => $direction]),
            'parent' => __('Allocation updated — :name', ['name' => $dependentName]),
            default => __('Allocation change: :name', ['name' => $dependentName]),
        };
    }

    protected function body(): string
    {
        $this->change->loadMissing(['dependent', 'parent']);

        $dependentName = (string) ($this->change->dependent?->name ?? __('Dependent'));
        $parentName = (string) ($this->change->parent?->name ?? __('Parent'));
        $currency = Setting::get('general', 'currency', 'USD');
        $oldFmt = MoneyDisplay::format((float) $this->change->old_amount, $currency);
        $newFmt = MoneyDisplay::format((float) $this->change->new_amount, $currency);

        return match ($this->role) {
            'dependent' => __('Your monthly contribution allocation changed from :old to :new.', [
                'old' => $oldFmt,
                'new' => $newFmt,
            ]),
            'parent' => __('You updated :name\'s monthly allocation from :old to :new.', [
                'name' => $dependentName,
                'old' => $oldFmt,
                'new' => $newFmt,
            ]),
            default => __('Parent :parent changed :name\'s monthly allocation from :old to :new.', [
                'parent' => $parentName,
                'name' => $dependentName,
                'old' => $oldFmt,
                'new' => $newFmt,
            ]),
        };
    }

    protected function icon(): string
    {
        return match ($this->role) {
            'parent' => 'heroicon-o-check-circle',
            'admin' => 'heroicon-o-bell-alert',
            default => 'heroicon-o-adjustments-horizontal',
        };
    }

    protected function iconColor(): string
    {
        if ($this->role === 'parent') {
            return 'success';
        }

        if ($this->role === 'admin') {
            return 'info';
        }

        return $this->change->isIncrease() ? 'success' : 'warning';
    }

    protected function actionLabel(): string
    {
        return match ($this->role) {
            'dependent' => __('View contribution settings'),
            'parent' => __('View dependents'),
            default => __('View members'),
        };
    }

    protected function actionUrl(): ?string
    {
        $url = match ($this->role) {
            'dependent' => MyContributionSettingsPage::getUrl(panel: 'member'),
            'parent' => MyDependentResource::getUrl('index', panel: 'member'),
            default => MemberResource::getUrl('index'),
        };

        return TenantAbsoluteUrl::resolve($url);
    }
}
