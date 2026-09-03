<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\EvidenceChannelSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Setting::get(EvidenceChannelSettings::GROUP, EvidenceChannelSettings::KEY) === null) {
            Setting::set(
                EvidenceChannelSettings::GROUP,
                EvidenceChannelSettings::KEY,
                EvidenceChannelSettings::CHANNEL_BANK_CSV,
            );
        }
    }

    public function down(): void
    {
        Setting::query()
            ->where('group', EvidenceChannelSettings::GROUP)
            ->where('key', EvidenceChannelSettings::KEY)
            ->delete();
    }
};
