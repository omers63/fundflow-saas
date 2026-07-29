<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\AdminNotificationChannels;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\PushEventSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

final class FundStatusDigestNotification extends Notification
{
    use DeliversToAdminChannels;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(
        public array $digest,
        public string $actionUrl,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = PushEventSettings::filterChannels(
            AdminNotificationChannels::resolve(),
            $this->adminNotificationTemplateKey(),
        );

        if ($notifiable instanceof User && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->absoluteUrl(),
            'fund-status-digest',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): MailMessage {
            $copy = $this->adminTemplatedCopy($notifiable, NotificationTemplate::FAMILY_EMAIL);

            return (new MailMessage)
                ->subject($copy['title'] !== '' ? $copy['title'] : __('Fund status summary'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('Fund status digest (auto):'))
                ->line($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->action(__('Review in admin'), $this->absoluteUrl());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : __('Fund status summary'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-shield-check')
                ->iconColor('primary')
                ->actions([
                    Action::make('review')
                        ->label(__('Review in admin'))
                        ->url($this->absoluteUrl())
                        ->markAsRead(),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        return [
            'title' => __('Fund status summary'),
            'summary' => $this->fallbackBody(),
            'action_url' => $this->absoluteUrl(),
        ];
    }

    protected function fallbackBody(): string
    {
        $balances = (array) ($this->digest['balances'] ?? []);
        $counts = (array) ($this->digest['counts'] ?? []);

        $cash = (float) ($balances['cash'] ?? 0.0);
        $fund = (float) ($balances['fund'] ?? 0.0);
        $bank = (float) ($balances['bank'] ?? 0.0);

        $pendingDeposits = (int) ($counts['pending_deposits'] ?? 0);
        $pendingContributions = (int) ($counts['pending_contributions'] ?? 0);
        $pendingMemberRequests = (int) ($counts['pending_member_requests'] ?? 0);
        $pendingCashOutRequests = (int) ($counts['pending_cash_out_requests'] ?? 0);
        $pendingMembershipApps = (int) ($counts['pending_membership_applications'] ?? 0);
        $loanQueue = (int) ($counts['loan_queue'] ?? 0);

        $overdueInstallments = (int) ($counts['overdue_installments'] ?? 0);
        $contributionArrearsPeriods = (int) ($counts['contribution_arrears_periods'] ?? 0);
        $membersInArrears = (int) ($counts['members_in_arrears'] ?? 0);

        $openReconciliationExceptions = (int) ($counts['open_reconciliation_exceptions'] ?? 0);
        $openCycleTotal = (int) ($counts['open_cycle_arrears_total'] ?? 0);
        $periodLabel = (string) ($counts['open_cycle_period_label'] ?? '');
        $catalogIssues = (int) ($counts['catalog_consistency_issue_count'] ?? 0);

        $formatMoney = fn (float $a): string => InsightFormatter::money($a);

        $lines = [];
        $lines[] = __('Fund status summary for :date', ['date' => BusinessDay::now()->toDateString()]);
        $lines[] = __('Balances: Cash :cash · Fund :fund · Bank :bank.', [
            'cash' => $formatMoney($cash),
            'fund' => $formatMoney($fund),
            'bank' => $formatMoney($bank),
        ]);

        $lines[] = '';
        $lines[] = __('New deposits (pending): :n', ['n' => $pendingDeposits]);
        $lines[] = __('Pending contributions: :n', ['n' => $pendingContributions]);
        $lines[] = __('Requests pending: Member :member · Cash-out :cashout · Membership :apps', [
            'member' => $pendingMemberRequests,
            'cashout' => $pendingCashOutRequests,
            'apps' => $pendingMembershipApps,
        ]);
        $lines[] = __('Loan queue: :n', ['n' => $loanQueue]);

        $lines[] = '';
        $lines[] = __('Issues: overdue installments :overdue · contribution arrears periods :arrears_periods · members in arrears :members', [
            'overdue' => $overdueInstallments,
            'arrears_periods' => $contributionArrearsPeriods,
            'members' => $membersInArrears,
        ]);
        $lines[] = __('Reconciliation exceptions: :n', ['n' => $openReconciliationExceptions]);

        if ($periodLabel !== '') {
            $lines[] = __('Open-cycle arrears (total items) for :period: :n', [
                'period' => $periodLabel,
                'n' => $openCycleTotal,
            ]);
        } else {
            $lines[] = __('Open-cycle arrears (total items): :n', ['n' => $openCycleTotal]);
        }

        if ($catalogIssues > 0) {
            $lines[] = __('Catalog consistency issues detected: :n', ['n' => $catalogIssues]);
        }

        return implode("\n", $lines);
    }

    protected function absoluteUrl(): string
    {
        return TenantAbsoluteUrl::resolve($this->actionUrl);
    }
}
