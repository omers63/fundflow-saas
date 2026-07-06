<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\User;
use App\Notifications\Tenant\TestAdminWebPushNotification;
use App\Support\WebPushNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class SendTestWebPushCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'webpush:test
                            {--email= : Send only to the admin with this email}';

    protected $description = 'Send a test web push notification to tenant admin users (or a specific email with --email)';

    public function handle(): int
    {
        if (! WebPushNotification::enabled()) {
            $this->error(__('Web push is not configured. Run php artisan webpush:vapid and set WEBPUSH_VAPID_* in .env.'));

            return self::FAILURE;
        }

        $query = User::query();

        if ($email = $this->option('email')) {
            $query->where('email', $email);
        } else {
            $query->where('is_admin', true);
        }

        $admins = $query->get();

        if ($admins->isEmpty()) {
            $this->warn(__('No matching admin users found.'));

            return self::FAILURE;
        }

        $sent = 0;

        foreach ($admins as $admin) {
            if ($admin->pushSubscriptions()->doesntExist()) {
                $this->line(__('Skipping :email (no push subscription).', ['email' => $admin->email]));

                continue;
            }

            Notification::send($admin, new TestAdminWebPushNotification);
            $sent++;
            $this->info(__('Test push queued for :email.', ['email' => $admin->email]));
        }

        if ($sent === 0) {
            $this->warn(__('No admins with an active push subscription were notified. Open the admin panel on your phone and allow notifications first.'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
