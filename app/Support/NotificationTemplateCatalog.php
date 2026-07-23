<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\NotificationTemplate;
use App\Notifications\Tenant\AccountTransactionReversedNotification;
use App\Notifications\Tenant\CashOutBankClearedNotification;
use App\Notifications\Tenant\CashOutRequestAcceptedNotification;
use App\Notifications\Tenant\CashOutRequestRejectedNotification;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\ContributionLateFeeAppliedNotification;
use App\Notifications\Tenant\ContributionPostedNotification;
use App\Notifications\Tenant\DependentAllocationChangedNotification;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingBankClearedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\GuarantorLoanApplicationNotification;
use App\Notifications\Tenant\LoanApprovedNotification;
use App\Notifications\Tenant\LoanCancelledNotification;
use App\Notifications\Tenant\LoanDefaultGuarantorNotification;
use App\Notifications\Tenant\LoanDefaultWarningNotification;
use App\Notifications\Tenant\LoanDisbursedNotification;
use App\Notifications\Tenant\LoanEarlySettledNotification;
use App\Notifications\Tenant\LoanEligibilityOverrideApprovedNotification;
use App\Notifications\Tenant\LoanEligibilityOverrideRejectedNotification;
use App\Notifications\Tenant\LoanGuarantorTransferNotification;
use App\Notifications\Tenant\LoanPartialDisbursementNotification;
use App\Notifications\Tenant\LoanRejectedNotification;
use App\Notifications\Tenant\LoanRepaymentAppliedNotification;
use App\Notifications\Tenant\LoanRepaymentDueNotification;
use App\Notifications\Tenant\LoanSettledNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\MemberDirectMessageNotification;
use App\Notifications\Tenant\MembershipApplicationApprovedNotification;
use App\Notifications\Tenant\MembershipApplicationRejectedNotification;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Notifications\Tenant\SupportRequestStatusNotification;
use App\Services\Tenant\NotificationPreferenceService;

/**
 * Registry of member alert template keys, categories, and default EN/AR copy.
 *
 * @phpstan-type TemplateDefault array{
 *     category: string,
 *     label: string,
 *     variables: list<string>,
 *     supported: list<string>,
 *     en: array{subject: string, body: string},
 *     ar: array{subject: string, body: string}
 * }
 * @phpstan-type ClassBinding array{key: string, category: string}
 */
final class NotificationTemplateCatalog
{
    /**
     * @return array<string, TemplateDefault>
     */
    public static function definitions(): array
    {
        $allChannels = [
            NotificationPreferenceService::CH_IN_APP,
            NotificationPreferenceService::CH_PUSH,
            NotificationPreferenceService::CH_EMAIL,
            NotificationPreferenceService::CH_SMS,
            NotificationPreferenceService::CH_WHATSAPP,
        ];

        return [
            'contribution_due' => [
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
                'label' => 'Contribution due',
                'variables' => ['member_name', 'amount', 'period', 'deadline', 'balance', 'action_url'],
                'supported' => $allChannels,
                'en' => [
                    'subject' => 'Contribution due',
                    'body' => '{{amount}} due for {{period}} by {{deadline}}. Cash balance: {{balance}}.',
                ],
                'ar' => [
                    'subject' => 'مساهمة مستحقة',
                    'body' => '{{amount}} مستحقة عن {{period}} بحلول {{deadline}}. رصيد النقد: {{balance}}.',
                ],
            ],
            'contribution_posted' => [
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
                'label' => 'Contribution posted',
                'variables' => ['member_name', 'amount', 'period', 'action_url'],
                'supported' => $allChannels,
                'en' => [
                    'subject' => 'Contribution posted',
                    'body' => 'Your contribution of {{amount}} for {{period}} has been posted.',
                ],
                'ar' => [
                    'subject' => 'تم ترحيل المساهمة',
                    'body' => 'تم ترحيل مساهمتك بمبلغ {{amount}} عن {{period}}.',
                ],
            ],
            'contribution_late_fee' => [
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
                'label' => 'Contribution late fee',
                'variables' => ['member_name', 'amount', 'period', 'action_url'],
                'supported' => $allChannels,
                'en' => [
                    'subject' => 'Late fee applied',
                    'body' => 'A late fee of {{amount}} was applied for {{period}}.',
                ],
                'ar' => [
                    'subject' => 'تم تطبيق رسوم تأخير',
                    'body' => 'طُبّقت رسوم تأخير بمبلغ {{amount}} عن {{period}}.',
                ],
            ],
            'fund_posting_accepted' => [
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
                'label' => 'Deposit accepted',
                'variables' => ['member_name', 'amount', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ACCOUNT_ALERTS]['supported'],
                'en' => [
                    'subject' => 'Deposit accepted',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تم قبول الإيداع',
                    'body' => '{{body}}',
                ],
            ],
            'fund_posting_rejected' => [
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
                'label' => 'Deposit rejected',
                'variables' => ['member_name', 'amount', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ACCOUNT_ALERTS]['supported'],
                'en' => [
                    'subject' => 'Deposit rejected',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تم رفض الإيداع',
                    'body' => '{{body}}',
                ],
            ],
            'fund_posting_bank_cleared' => [
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
                'label' => 'Deposit bank cleared',
                'variables' => ['member_name', 'amount', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ACCOUNT_ALERTS]['supported'],
                'en' => [
                    'subject' => 'Deposit matched to bank',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تمت مطابقة الإيداع مع البنك',
                    'body' => '{{body}}',
                ],
            ],
            'member_direct_message' => [
                'category' => NotificationPreferenceService::BROADCASTS,
                'label' => 'Direct message from administration',
                'variables' => ['member_name', 'sender_name', 'preview', 'subject', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::BROADCASTS]['supported'],
                'en' => [
                    'subject' => '{{subject}}',
                    'body' => '{{sender_name}}: {{preview}}',
                ],
                'ar' => [
                    'subject' => '{{subject}}',
                    'body' => '{{sender_name}}: {{preview}}',
                ],
            ],
            'member_announcement' => [
                'category' => NotificationPreferenceService::BROADCASTS,
                'label' => 'Admin announcement',
                'variables' => ['member_name', 'title', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::BROADCASTS]['supported'],
                'en' => [
                    'subject' => '{{title}}',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => '{{title}}',
                    'body' => '{{body}}',
                ],
            ],
            'loan_repayment_due' => [
                'category' => NotificationPreferenceService::LOAN_REPAYMENT,
                'label' => 'Loan repayment due',
                'variables' => ['member_name', 'amount', 'deadline', 'loan_id', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::LOAN_REPAYMENT]['supported'],
                'en' => [
                    'subject' => 'Loan repayment due',
                    'body' => 'Installment of {{amount}} is due by {{deadline}} for loan #{{loan_id}}.',
                ],
                'ar' => [
                    'subject' => 'قسط قرض مستحق',
                    'body' => 'قسط بمبلغ {{amount}} مستحق بحلول {{deadline}} للقرض #{{loan_id}}.',
                ],
            ],
            'dependent_allocation_changed' => [
                'category' => NotificationPreferenceService::ALLOCATIONS,
                'label' => 'Allocation changed',
                'variables' => ['member_name', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ALLOCATIONS]['supported'],
                'en' => [
                    'subject' => 'Allocation updated',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تم تحديث التخصيص',
                    'body' => '{{body}}',
                ],
            ],
            'monthly_statement' => [
                'category' => NotificationPreferenceService::STATEMENTS,
                'label' => 'Monthly statement',
                'variables' => ['member_name', 'period', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::STATEMENTS]['supported'],
                'en' => [
                    'subject' => 'Monthly statement ready',
                    'body' => 'Your statement for {{period}} is ready.',
                ],
                'ar' => [
                    'subject' => 'كشف الحساب الشهري جاهز',
                    'body' => 'كشف حسابك عن {{period}} جاهز.',
                ],
            ],
            'membership_approved' => [
                'category' => NotificationPreferenceService::MEMBERSHIP,
                'label' => 'Membership approved',
                'variables' => ['member_name', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::MEMBERSHIP]['supported'],
                'en' => [
                    'subject' => 'Membership approved',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تمت الموافقة على العضوية',
                    'body' => '{{body}}',
                ],
            ],
            'membership_rejected' => [
                'category' => NotificationPreferenceService::MEMBERSHIP,
                'label' => 'Membership rejected',
                'variables' => ['member_name', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::MEMBERSHIP]['supported'],
                'en' => [
                    'subject' => 'Membership application update',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'تحديث طلب العضوية',
                    'body' => '{{body}}',
                ],
            ],
            'generic_member_alert' => [
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
                'label' => 'Generic member alert',
                'variables' => ['member_name', 'title', 'body', 'action_url'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ACCOUNT_ALERTS]['supported'],
                'en' => [
                    'subject' => '{{title}}',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => '{{title}}',
                    'body' => '{{body}}',
                ],
            ],
        ];
    }

    /**
     * @return array<class-string, ClassBinding>
     */
    public static function classBindings(): array
    {
        return [
            ContributionDueNotification::class => [
                'key' => 'contribution_due',
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
            ],
            ContributionPostedNotification::class => [
                'key' => 'contribution_posted',
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
            ],
            ContributionLateFeeAppliedNotification::class => [
                'key' => 'contribution_late_fee',
                'category' => NotificationPreferenceService::CONTRIBUTIONS,
            ],
            FundPostingAcceptedNotification::class => [
                'key' => 'fund_posting_accepted',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            FundPostingRejectedNotification::class => [
                'key' => 'fund_posting_rejected',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            FundPostingBankClearedNotification::class => [
                'key' => 'fund_posting_bank_cleared',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            MemberDirectMessageNotification::class => [
                'key' => 'member_direct_message',
                'category' => NotificationPreferenceService::BROADCASTS,
            ],
            MemberAnnouncementNotification::class => [
                'key' => 'member_announcement',
                'category' => NotificationPreferenceService::BROADCASTS,
            ],
            LoanRepaymentDueNotification::class => [
                'key' => 'loan_repayment_due',
                'category' => NotificationPreferenceService::LOAN_REPAYMENT,
            ],
            DependentAllocationChangedNotification::class => [
                'key' => 'dependent_allocation_changed',
                'category' => NotificationPreferenceService::ALLOCATIONS,
            ],
            MonthlyStatementNotification::class => [
                'key' => 'monthly_statement',
                'category' => NotificationPreferenceService::STATEMENTS,
            ],
            MembershipApplicationApprovedNotification::class => [
                'key' => 'membership_approved',
                'category' => NotificationPreferenceService::MEMBERSHIP,
            ],
            MembershipApplicationRejectedNotification::class => [
                'key' => 'membership_rejected',
                'category' => NotificationPreferenceService::MEMBERSHIP,
            ],
            CashOutRequestAcceptedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            CashOutRequestRejectedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            CashOutBankClearedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            AccountTransactionReversedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            LoanSubmittedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanApprovedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanRejectedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanCancelledNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanDisbursedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanPartialDisbursementNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanRepaymentAppliedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_REPAYMENT,
            ],
            LoanSettledNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanEarlySettledNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanDefaultWarningNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ALERTS,
            ],
            LoanDefaultGuarantorNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ALERTS,
            ],
            LoanGuarantorTransferNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ALERTS,
            ],
            GuarantorLoanApplicationNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ALERTS,
            ],
            LoanEligibilityOverrideApprovedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            LoanEligibilityOverrideRejectedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::LOAN_ACTIVITY,
            ],
            MemberStatusChangedNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
            SupportRequestStatusNotification::class => [
                'key' => 'generic_member_alert',
                'category' => NotificationPreferenceService::ACCOUNT_ALERTS,
            ],
        ];
    }

    public static function keyFor(string $notificationClass): ?string
    {
        return self::classBindings()[$notificationClass]['key'] ?? null;
    }

    public static function categoryFor(string $notificationClass): ?string
    {
        return self::classBindings()[$notificationClass]['category'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function supportedChannelsFor(string $notificationClass): array
    {
        $key = self::keyFor($notificationClass);
        $category = self::categoryFor($notificationClass);

        if ($key !== null && isset(self::definitions()[$key]['supported'])) {
            return self::definitions()[$key]['supported'];
        }

        if ($category !== null && isset(NotificationPreferenceService::CATEGORIES[$category]['supported'])) {
            return NotificationPreferenceService::CATEGORIES[$category]['supported'];
        }

        return [
            NotificationPreferenceService::CH_IN_APP,
            NotificationPreferenceService::CH_PUSH,
            NotificationPreferenceService::CH_EMAIL,
            NotificationPreferenceService::CH_SMS,
            NotificationPreferenceService::CH_WHATSAPP,
        ];
    }

    /**
     * @return TemplateDefault|null
     */
    public static function definition(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    /**
     * @return array{subject: string, body: string}|null
     */
    public static function defaultContent(string $key, string $locale): ?array
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return null;
        }

        return $locale === 'ar' ? $definition['ar'] : $definition['en'];
    }

    public static function restoreDefaults(string $key): void
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return;
        }

        foreach (['en', 'ar'] as $locale) {
            $content = $definition[$locale];
            foreach (NotificationTemplate::channelFamilies() as $family) {
                NotificationTemplate::query()->updateOrCreate(
                    [
                        'key' => $key,
                        'locale' => $locale,
                        'channel_family' => $family,
                    ],
                    [
                        'subject' => $content['subject'],
                        'body_markdown' => $content['body'],
                    ],
                );
            }
        }
    }

    public static function seedMissingDefaults(): int
    {
        $created = 0;

        foreach (array_keys(self::definitions()) as $key) {
            foreach (['en', 'ar'] as $locale) {
                $content = self::defaultContent($key, $locale);
                if ($content === null) {
                    continue;
                }

                foreach (NotificationTemplate::channelFamilies() as $family) {
                    $exists = NotificationTemplate::query()
                        ->where('key', $key)
                        ->where('locale', $locale)
                        ->where('channel_family', $family)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    NotificationTemplate::query()->create([
                        'key' => $key,
                        'locale' => $locale,
                        'channel_family' => $family,
                        'subject' => $content['subject'],
                        'body_markdown' => $content['body'],
                    ]);
                    $created++;
                }
            }
        }

        return $created;
    }
}
