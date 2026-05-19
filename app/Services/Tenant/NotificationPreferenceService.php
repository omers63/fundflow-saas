<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\MemberCommunicationPreference;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\CommunicationSettings;

/**
 * Resolves per-member notification channel preferences for each category.
 */
final class NotificationPreferenceService
{
    public const CONTRIBUTIONS = 'contributions';

    public const LOAN_REPAYMENT = 'loan_repayment';

    public const LOAN_ACTIVITY = 'loan_activity';

    public const LOAN_ALERTS = 'loan_alerts';

    public const MEMBERSHIP = 'membership';

    public const STATEMENTS = 'statements';

    public const BROADCASTS = 'broadcasts';

    public const ACCOUNT_ALERTS = 'account_alerts';

    public const ALLOCATIONS = 'allocations';

    public const CH_IN_APP = 'in_app';

    public const CH_EMAIL = 'email';

    public const CH_SMS = 'sms';

    public const CH_WHATSAPP = 'whatsapp';

    public const CHANNEL_MAP = [
        self::CH_IN_APP => 'database',
        self::CH_EMAIL => 'mail',
        self::CH_SMS => SmsChannel::class,
        self::CH_WHATSAPP => WhatsAppChannel::class,
    ];

    /**
     * @var array<string, array{label: string, description: string, icon: string, supported: list<string>, defaults: list<string>, forced: list<string>}>
     */
    public const CATEGORIES = [
        self::CONTRIBUTIONS => [
            'label' => 'Contributions',
            'description' => 'Monthly contribution reminders and payment confirmations.',
            'icon' => 'heroicon-o-arrow-trending-up',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::LOAN_REPAYMENT => [
            'label' => 'Loan repayments',
            'description' => 'Upcoming installment reminders and repayment confirmations.',
            'icon' => 'heroicon-o-banknotes',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::LOAN_ACTIVITY => [
            'label' => 'Loan activity',
            'description' => 'Loan approvals, disbursements, settlements, and cancellations.',
            'icon' => 'heroicon-o-document-text',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::LOAN_ALERTS => [
            'label' => 'Loan alerts',
            'description' => 'Default warnings and guarantor liability notifications.',
            'icon' => 'heroicon-o-exclamation-triangle',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP, self::CH_EMAIL],
        ],
        self::MEMBERSHIP => [
            'label' => 'Membership',
            'description' => 'Membership application approval or rejection updates.',
            'icon' => 'heroicon-o-identification',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::STATEMENTS => [
            'label' => 'Monthly statements',
            'description' => 'Monthly account statement generation and delivery.',
            'icon' => 'heroicon-o-document-chart-bar',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::BROADCASTS => [
            'label' => 'Admin announcements',
            'description' => 'Important messages from fund administration.',
            'icon' => 'heroicon-o-megaphone',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
        self::ACCOUNT_ALERTS => [
            'label' => 'Account alerts',
            'description' => 'Delinquency, suspension, and account restoration notices.',
            'icon' => 'heroicon-o-shield-exclamation',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'forced' => [self::CH_IN_APP, self::CH_EMAIL],
        ],
        self::ALLOCATIONS => [
            'label' => 'Allocation changes',
            'description' => 'When your parent changes your monthly contribution allocation.',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'supported' => [self::CH_IN_APP, self::CH_EMAIL, self::CH_SMS, self::CH_WHATSAPP],
            'defaults' => [self::CH_IN_APP, self::CH_EMAIL],
            'forced' => [self::CH_IN_APP],
        ],
    ];

    /**
     * @param  list<string>  $supportedLogical
     * @return list<string|class-string>
     */
    public static function resolve(object $notifiable, string $type, array $supportedLogical): array
    {
        $meta = self::CATEGORIES[$type] ?? null;
        $forced = $meta['forced'] ?? [self::CH_IN_APP];

        $preferred = self::preferredChannels($notifiable, $type);

        $effective = array_values(array_unique(array_merge($forced, $preferred)));
        $toSend = array_values(array_intersect($effective, $supportedLogical));

        if (in_array(self::CH_IN_APP, $supportedLogical, true) && ! in_array(self::CH_IN_APP, $toSend, true)) {
            $toSend[] = self::CH_IN_APP;
        }

        $systemEnabled = CommunicationSettings::enabledLogicalChannels();
        $toSend = array_values(array_intersect($toSend, $systemEnabled));

        if (
            in_array(self::CH_IN_APP, $systemEnabled, true)
            && in_array(self::CH_IN_APP, $supportedLogical, true)
            && ! in_array(self::CH_IN_APP, $toSend, true)
        ) {
            $toSend[] = self::CH_IN_APP;
        }

        $drivers = [];
        foreach (array_unique($toSend) as $logical) {
            if (isset(self::CHANNEL_MAP[$logical])) {
                $drivers[] = self::CHANNEL_MAP[$logical];
            }
        }

        return array_values($drivers);
    }

    /**
     * @return list<string|class-string>
     */
    public static function resolveMailOnly(object $notifiable, string $type): array
    {
        return self::resolve($notifiable, $type, [self::CH_IN_APP, self::CH_EMAIL]);
    }

    /**
     * @return list<string>
     */
    private static function preferredChannels(object $notifiable, string $type): array
    {
        $meta = self::CATEGORIES[$type] ?? [];
        $defaults = $meta['defaults'] ?? [self::CH_IN_APP, self::CH_EMAIL];

        if (! isset($notifiable->id)) {
            return $defaults;
        }

        return MemberCommunicationPreference::channelsFor(
            (int) $notifiable->id,
            $type,
            $defaults,
        );
    }
}
