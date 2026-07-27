<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Filament\Tenant\Support\AutomationScheduleFormSchema;
use App\Models\Tenant\Setting;
use App\Support\AutomationScheduleSettings;
use App\Support\DefaultTenantSettings;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

trait InteractsWithAutomationScheduleForm
{
    /** @var array<string, mixed>|null */
    public ?array $automationScheduleData = [];

    public function mountAutomationScheduleForm(): void
    {
        $this->fillAutomationScheduleForm();
    }

    public function fillAutomationScheduleForm(): void
    {
        $contribution = Setting::getGroup('contribution');

        $this->automationScheduleForm->fill([
            'cycle_start_day' => $contribution['cycle_start_day'] ?? DefaultTenantSettings::CYCLE_START_DAY,
            ...AutomationScheduleSettings::allForForm(),
        ]);
    }

    public function automationScheduleForm(Schema $schema): Schema
    {
        return $schema
            ->components(AutomationScheduleFormSchema::sections())
            ->statePath('automationScheduleData');
    }

    public function saveAutomationSchedule(): void
    {
        abort_unless(auth('tenant')->check(), 403);

        $state = $this->automationScheduleForm->getState();

        Setting::set('contribution', 'cycle_start_day', $state['cycle_start_day']);
        AutomationScheduleSettings::saveFromForm($state);

        Notification::make()
            ->title(__('Automation schedule saved'))
            ->success()
            ->send();

        $this->fillAutomationScheduleForm();
    }
}
