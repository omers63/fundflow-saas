<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\SmsClearing\Pages\ListSmsClearing;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @deprecated Use {@see SmsClearingResource} / {@see ListSmsClearing}.
 */
class SmsImportsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sms-imports-legacy';

    protected string $view = 'filament.tenant.pages.sms-imports-legacy';

    public static function canAccess(): bool
    {
        return auth('tenant')->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $parameters = [];

        $legacySubTab = request()->string('smsSubTab')->toString();
        $tab = request()->string('tab')->toString();

        if (filled($tab)) {
            $normalizedTab = SmsClearingTabRegistry::normalizeTab($tab);
            $parameters['tab'] = $normalizedTab;
        } elseif ($legacySubTab === 'history') {
            $parameters['tab'] = SmsClearingTabRegistry::TAB_HISTORY;
        }

        if ($queueFilter = request()->string('queueFilter')->toString()) {
            $parameters['queueFilter'] = SmsClearingTabRegistry::normalizeQueueFilter($queueFilter);
        }

        if ($historySection = request()->string('historySection')->toString()) {
            $parameters['historySection'] = SmsClearingTabRegistry::normalizeHistorySection($historySection);
        }

        $this->redirect(SmsClearingResource::getUrl('index', $parameters), navigate: true);
    }

    public function getTitle(): string|Htmlable
    {
        return __('SMS clearing');
    }
}
