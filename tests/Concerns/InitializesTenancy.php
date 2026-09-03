<?php

namespace Tests\Concerns;

use App\Models\Central\Tenant;
use App\Models\Tenant\Setting;
use App\Support\AutomationScheduleSettings;
use App\Support\BusinessDaySettings;
use App\Support\EvidenceChannelSettings;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

trait InitializesTenancy
{
    protected static bool $migrated = false;

    public function initializeTenancy(array $attributes = []): Tenant
    {
        $tenancy = tenancy();

        if ($tenancy->initialized && config()->has('database.connections.tenant')) {
            $tenant = tenant();
            assert($tenant instanceof Tenant);

            if ($attributes) {
                $tenant->update($attributes);
            }

            BusinessDaySettings::saveFromForm(null);
            Setting::set(AutomationScheduleSettings::GROUP, 'auto_accept_deposits', '0');
            EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_BANK_CSV);

            return $tenant;
        }

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        $tenant = Tenant::query()->find('testing');

        if (! $tenant) {
            $tenant = Tenant::factory()->state(['id' => 'testing'])->createQuietly();
        }

        if (! static::$migrated) {
            $this->artisan('tenants:migrate', [
                '--tenants' => ['testing'],
            ]);

            static::$migrated = true;
        }

        $tenancy->initialize($tenant);

        if (! config()->has('database.connections.tenant')) {
            app(DatabaseTenancyBootstrapper::class)->bootstrap($tenant);
        }

        BusinessDaySettings::saveFromForm(null);
        Setting::set(AutomationScheduleSettings::GROUP, 'auto_accept_deposits', '0');
        EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_BANK_CSV);

        return $tenant;
    }
}
