<?php

declare(strict_types=1);

use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberOnboardingGreetingNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->timestamp('onboarding_greeting_sent_at')->nullable()->after('joined_at');
        });

        if (! Schema::hasTable('notifications')) {
            return;
        }

        $userIds = DB::table('notifications')
            ->where('type', MemberOnboardingGreetingNotification::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->pluck('notifiable_id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('members')
            ->whereIn('user_id', $userIds)
            ->whereNull('onboarding_greeting_sent_at')
            ->update(['onboarding_greeting_sent_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('onboarding_greeting_sent_at');
        });
    }
};
