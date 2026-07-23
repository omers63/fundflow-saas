<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\NotificationTemplate;
use App\Notifications\Tenant\AccountTransactionReversedNotification;
use App\Notifications\Tenant\AdminDirectMessageNotification;
use App\Notifications\Tenant\CashOutBankClearedNotification;
use App\Notifications\Tenant\CashOutRequestAcceptedNotification;
use App\Notifications\Tenant\CashOutRequestRejectedNotification;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\ContributionLateFeeAppliedNotification;
use App\Notifications\Tenant\ContributionPostedNotification;
use App\Notifications\Tenant\DelinquencyDigestNotification;
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
use App\Notifications\Tenant\LoanGuarantorTransferAdminNotification;
use App\Notifications\Tenant\LoanGuarantorTransferNotification;
use App\Notifications\Tenant\LoanPartialDisbursementNotification;
use App\Notifications\Tenant\LoanRejectedNotification;
use App\Notifications\Tenant\LoanRepaymentAppliedNotification;
use App\Notifications\Tenant\LoanRepaymentDueNotification;
use App\Notifications\Tenant\LoanSettledNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\MemberDirectMessageNotification;
use App\Notifications\Tenant\MemberOnboardingGreetingNotification;
use App\Notifications\Tenant\MembershipApplicationApprovedNotification;
use App\Notifications\Tenant\MembershipApplicationRejectedNotification;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Notifications\Tenant\NewCashOutRequestNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use App\Notifications\Tenant\NewLoanApplicationNotification;
use App\Notifications\Tenant\NewLoanEligibilityOverrideRequestNotification;
use App\Notifications\Tenant\NewMemberRequestNotification;
use App\Notifications\Tenant\NewMembershipApplicationNotification;
use App\Notifications\Tenant\NewSupportRequestNotification;
use App\Notifications\Tenant\ReconciliationDigestNotification;
use App\Notifications\Tenant\ReconciliationExceptionRaisedNotification;
use App\Notifications\Tenant\SupportRequestStatusNotification;
use App\Services\Tenant\NotificationPreferenceService;

/**
 * Registry of alert template keys (member + admin/automation), categories, and default EN/AR copy.
 *
 * @phpstan-type TemplateDefault array{
 *     category: string,
 *     label: string,
 *     audience?: 'member'|'admin',
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
            'member_onboarding_greeting' => [
                'category' => NotificationPreferenceService::MEMBERSHIP,
                'label' => 'Member onboarding greeting',
                'variables' => ['member_name', 'fund_name', 'action_url', 'action_label'],
                'supported' => NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::MEMBERSHIP]['supported'],
                'en' => [
                    'subject' => 'Welcome to {{fund_name}}',
                    'body' => <<<'MD'
Hello {{member_name}},

Welcome to **{{fund_name}}**.

## About the fund

{{fund_name}} is a family fund where members contribute regularly, build a shared pool, and access loans and support according to the fund’s rules. Our goals are mutual support, clear balances, and orderly monthly collections.

## Your accounts

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;">
<tr>
<td width="33%" valign="top" style="width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">💵</div>
<strong style="color:#0e7490;">Cash</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">Money available to spend inside the fund — pay contributions, repay loans, or request a cash-out.</span>
</td>
<td width="33%" valign="top" style="width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">🏦</div>
<strong style="color:#15803d;">Fund</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">Your share of the pool. Monthly contributions increase this balance and reflect your standing.</span>
</td>
<td width="33%" valign="top" style="width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">📄</div>
<strong style="color:#c2410c;">Loan</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">What you still owe if you have an approved loan. It goes down as EMI repayments are applied.</span>
</td>
</tr>
</table>

Parents may also manage **dependents** and set how much of the household contribution is allocated to each dependent.

## How money moves

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;">
<tr>
<td width="50%" valign="top" style="width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0284c7;border-radius:12px;padding:14px;">
<strong style="color:#0369a1;">Into your cash (then the fund)</strong>
<ul style="margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;">
<li><strong>Deposits</strong> — bank transfer accepted → cash rises</li>
<li><strong>Contributions</strong> — cash → fund share each cycle</li>
<li><strong>EMI repayments</strong> — cash reduces your loan balance</li>
<li><strong>Dependent allocation</strong> — parent splits household contribution</li>
</ul>
</td>
<td width="50%" valign="top" style="width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #059669;border-radius:12px;padding:14px;">
<strong style="color:#047857;">From the fund to you</strong>
<ul style="margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;">
<li><strong>Loan disbursement</strong> — payout credited to your cash</li>
<li><strong>Cash-outs</strong> — approved withdrawal leaves your cash</li>
</ul>
</td>
</tr>
</table>

Keep enough **cash** before collection windows so contributions and EMI can clear without arrears.

## How to access the fund

1. Open the member portal with the button below (or your fund’s member login page).
2. Sign in with the email and password you were given or set during registration.
3. On first visit, install the portal as an app for quicker access (see below).

## Install the app (PWA)

### On a computer (Chrome or Edge)

1. Open the member portal in **Chrome** or **Microsoft Edge**.
2. Look for the install icon in the address bar, or open the browser menu → **Install app** / **Apps** → **Install this site as an app**.
3. Confirm. The fund opens in its own window from your desktop or Start menu.

### On Android

1. Open the member portal in **Chrome**.
2. Tap the menu (⋮) → **Install app** or **Add to Home screen**.
3. Confirm. An icon appears on your home screen.

### On iPhone or iPad

1. Open the member portal in **Safari** (required on iOS).
2. Tap the **Share** button.
3. Choose **Add to Home Screen**, then **Add**.

## Permissions to accept

When prompted, please allow:

- **Notifications** — contribution due dates, loan reminders, and important fund messages
- **Camera** only if the portal asks for document uploads (not required for basic use)

You can change notification preferences anytime under **Settings → Notifications** in the member portal.

## Using the member portal

- **Home** — balances, due items, and quick actions
- **Contributions** — what is due and your history
- **Loans** — request or track repayments when eligible
- **Statements** — download monthly statements
- **Messages / Alerts** — admin messages and system notifications
- **Settings** — profile, language, and notification preferences

If you need help, reply to this email or contact your fund administrators.

Welcome aboard!
MD,
                ],
                'ar' => [
                    'subject' => 'مرحبًا بك في {{fund_name}}',
                    'body' => <<<'MD'
مرحبًا {{member_name}}،

أهلًا بك في **{{fund_name}}**.

## عن الصندوق

{{fund_name}} صندوق عائلي يساهم فيه الأعضاء بانتظام، ويبنيون رصيدًا مشتركًا، ويحصلون على القروض والدعم وفق قواعد الصندوق. أهدافنا هي الدعم المتبادل، ووضوح الأرصدة، وتحصيل الاشتراكات الشهرية بشكل منظم.

## حساباتك

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;">
<tr>
<td width="33%" valign="top" style="width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">💵</div>
<strong style="color:#0e7490;">النقد</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">المال المتاح للاستخدام داخل الصندوق — دفع الاشتراكات، سداد القروض، أو طلب سحب نقدي.</span>
</td>
<td width="33%" valign="top" style="width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">🏦</div>
<strong style="color:#15803d;">الصندوق</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">حصتك في المجموع المشترك. تزيد الاشتراكات الشهرية هذا الرصيد وتعكس مكانتك.</span>
</td>
<td width="33%" valign="top" style="width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;">
<div style="font-size:20px;line-height:1;margin-bottom:6px;">📄</div>
<strong style="color:#c2410c;">القرض</strong><br>
<span style="color:#475569;font-size:13px;line-height:1.45;">ما لا يزال مستحقًا عليك إذا كان لديك قرض معتمد. ينخفض مع تطبيق الأقساط.</span>
</td>
</tr>
</table>

قد يدير ولي الأمر أيضًا **التابعين** ويحدد مقدار ما يُخصَّص من اشتراك الأسرة لكل تابع.

## كيف تتحرك الأموال

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;">
<tr>
<td width="50%" valign="top" style="width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #0284c7;border-radius:12px;padding:14px;">
<strong style="color:#0369a1;">إلى نقدك (ثم إلى الصندوق)</strong>
<ul style="margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;">
<li><strong>الإيداعات</strong> — بعد قبول التحويل البنكي يرتفع النقد</li>
<li><strong>الاشتراكات</strong> — من النقد إلى حصة الصندوق كل دورة</li>
<li><strong>سداد الأقساط</strong> — النقد يخفض رصيد القرض</li>
<li><strong>تخصيص التابعين</strong> — ولي الأمر يقسّم اشتراك الأسرة</li>
</ul>
</td>
<td width="50%" valign="top" style="width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #059669;border-radius:12px;padding:14px;">
<strong style="color:#047857;">من الصندوق إليك</strong>
<ul style="margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;">
<li><strong>صرف القرض</strong> — يُضاف المبلغ إلى نقدك</li>
<li><strong>السحب النقدي</strong> — بعد الموافقة ينخفض رصيد النقد</li>
</ul>
</td>
</tr>
</table>

احرص على وجود **نقد** كافٍ قبل نوافذ التحصيل حتى تُسدَّد الاشتراكات والأقساط دون متأخرات.

## كيفية الدخول إلى الصندوق

1. افتح بوابة العضو عبر الزر أدناه (أو صفحة تسجيل دخول الأعضاء لصندوقك).
2. سجّل الدخول بالبريد وكلمة المرور التي حصلت عليها أو عيّنتها عند التسجيل.
3. في الزيارة الأولى، ثبّت البوابة كتطبيق لوصول أسرع (انظر أدناه).

## تثبيت التطبيق (PWA)

### على الحاسوب (Chrome أو Edge)

1. افتح بوابة العضو في **Chrome** أو **Microsoft Edge**.
2. ابحث عن أيقونة التثبيت في شريط العنوان، أو من قائمة المتصفح ← **تثبيت التطبيق** / **التطبيقات** ← **تثبيت هذا الموقع كتطبيق**.
3. أكّد. يفتح الصندوق في نافذة مستقلة من سطح المكتب أو قائمة ابدأ.

### على Android

1. افتح بوابة العضو في **Chrome**.
2. من القائمة (⋮) ← **تثبيت التطبيق** أو **إضافة إلى الشاشة الرئيسية**.
3. أكّد. تظهر أيقونة على الشاشة الرئيسية.

### على iPhone أو iPad

1. افتح بوابة العضو في **Safari** (مطلوب على iOS).
2. اضغط زر **المشاركة**.
3. اختر **إضافة إلى الشاشة الرئيسية** ثم **إضافة**.

## الأذونات التي يُفضّل قبولها

عند ظهور الطلب، يُرجى السماح بـ:

- **الإشعارات** — مواعيد الاشتراكات، تذكيرات القروض، ورسائل الصندوق المهمة
- **الكاميرا** فقط إذا طلبت البوابة رفع مستندات (غير مطلوبة للاستخدام الأساسي)

يمكنك تعديل تفضيلات الإشعارات في أي وقت من **الإعدادات ← الإشعارات** داخل بوابة العضو.

## استخدام بوابة العضو

- **الرئيسية** — الأرصدة والمستحقات والإجراءات السريعة
- **الاشتراكات** — المستحق وسجل الدفعات
- **القروض** — طلب القرض أو متابعة السداد عند الأهلية
- **الكشوف** — تنزيل كشوف الحساب الشهرية
- **الرسائل / التنبيهات** — رسائل الإدارة وإشعارات النظام
- **الإعدادات** — الملف الشخصي واللغة وتفضيلات الإشعارات

إذا احتجت مساعدة، اردّ على هذا البريد أو تواصل مع إدارة الصندوق.

مرحبًا بك معنا!
MD,
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
            ...self::adminDefinitions(),
        ];
    }

    /**
     * Admin / automation alerts (scheduler digests, operational review requests).
     *
     * @return array<string, TemplateDefault>
     */
    public static function adminDefinitions(): array
    {
        $bellPush = [
            NotificationPreferenceService::CH_IN_APP,
            NotificationPreferenceService::CH_PUSH,
        ];
        $bellPushEmail = [
            NotificationPreferenceService::CH_IN_APP,
            NotificationPreferenceService::CH_PUSH,
            NotificationPreferenceService::CH_EMAIL,
        ];

        return [
            'reconciliation_digest' => [
                'audience' => 'admin',
                'category' => 'automation',
                'label' => 'Reconciliation digest (automation)',
                'variables' => ['title', 'summary', 'mode', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => '{{title}}',
                    'body' => '{{summary}}',
                ],
                'ar' => [
                    'subject' => '{{title}}',
                    'body' => '{{summary}}',
                ],
            ],
            'delinquency_digest' => [
                'audience' => 'admin',
                'category' => 'automation',
                'label' => 'Delinquency digest (automation)',
                'variables' => ['overdue', 'arrears', 'delinquent', 'guarantor', 'action_url'],
                'supported' => $bellPushEmail,
                'en' => [
                    'subject' => 'Delinquency digest',
                    'body' => '{{overdue}} overdue installment(s) · {{arrears}} contribution period(s) in arrears · {{delinquent}} delinquent member(s).',
                ],
                'ar' => [
                    'subject' => 'ملخص التعثر',
                    'body' => '{{overdue}} قسط(أقساط) متأخر · {{arrears}} فترة(فترات) مساهمات متأخرة · {{delinquent}} عضو(أعضاء) متعثر.',
                ],
            ],
            'reconciliation_exception' => [
                'audience' => 'admin',
                'category' => 'automation',
                'label' => 'Reconciliation exception',
                'variables' => ['severity', 'code', 'domain', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'Reconciliation exception',
                    'body' => '{{severity}} exception {{code}} in {{domain}}.',
                ],
                'ar' => [
                    'subject' => 'استثناء مطابقة',
                    'body' => 'استثناء {{severity}}: {{code}} في {{domain}}.',
                ],
            ],
            'new_loan_application' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New loan application (admin)',
                'variables' => ['member_name', 'amount', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'New loan application',
                    'body' => '{{member_name}} applied for {{amount}}.',
                ],
                'ar' => [
                    'subject' => 'طلب قرض جديد',
                    'body' => '{{member_name}} تقدّم بطلب بمبلغ {{amount}}.',
                ],
            ],
            'new_fund_posting' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New deposit request (admin)',
                'variables' => ['member_name', 'amount', 'body', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'New deposit request',
                    'body' => '{{body}}',
                ],
                'ar' => [
                    'subject' => 'طلب إيداع جديد',
                    'body' => '{{body}}',
                ],
            ],
            'new_cash_out_request' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New cash-out request (admin)',
                'variables' => ['member_name', 'amount', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'New cash-out request',
                    'body' => '{{member_name}} requested {{amount}}.',
                ],
                'ar' => [
                    'subject' => 'طلب سحب نقدي جديد',
                    'body' => '{{member_name}} طلب {{amount}}.',
                ],
            ],
            'new_membership_application' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New membership application (admin)',
                'variables' => ['member_name', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'New membership application',
                    'body' => '{{member_name}} submitted a membership application.',
                ],
                'ar' => [
                    'subject' => 'طلب عضوية جديد',
                    'body' => '{{member_name}} قدّم طلب عضوية.',
                ],
            ],
            'new_support_request' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New support request (admin)',
                'variables' => ['request_id', 'subject', 'from', 'category', 'message', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'Support request #{{request_id}}: {{subject}}',
                    'body' => 'Request #{{request_id}} from {{from}}\nCategory: {{category}}\n\n{{message}}',
                ],
                'ar' => [
                    'subject' => 'طلب دعم #{{request_id}}: {{subject}}',
                    'body' => 'طلب #{{request_id}} من {{from}}\nالتصنيف: {{category}}\n\n{{message}}',
                ],
            ],
            'new_member_request' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'New member request (admin)',
                'variables' => ['member_name', 'request_type', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'New member request',
                    'body' => '{{member_name}} — {{request_type}}',
                ],
                'ar' => [
                    'subject' => 'طلب عضو جديد',
                    'body' => '{{member_name}} — {{request_type}}',
                ],
            ],
            'new_loan_eligibility_override' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'Loan eligibility review (admin)',
                'variables' => ['member_name', 'gate_count', 'first_gate', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'Loan eligibility review requested',
                    'body' => '{{member_name}} requested an eligibility review ({{gate_count}} blocked rule(s), first: {{first_gate}}).',
                ],
                'ar' => [
                    'subject' => 'طلب مراجعة أهلية القرض',
                    'body' => '{{member_name}} طلب مراجعة الأهلية ({{gate_count}} قاعدة محظورة، الأولى: {{first_gate}}).',
                ],
            ],
            'loan_guarantor_transfer_admin' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'Loan guarantor transfer (admin)',
                'variables' => ['loan_id', 'borrower_name', 'guarantor_name', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => 'Loan transferred to guarantor',
                    'body' => 'Loan #{{loan_id}} moved from {{borrower_name}} to guarantor {{guarantor_name}}.',
                ],
                'ar' => [
                    'subject' => 'نقل القرض إلى الكفيل',
                    'body' => 'القرض #{{loan_id}} نُقل من {{borrower_name}} إلى الكفيل {{guarantor_name}}.',
                ],
            ],
            'admin_direct_message' => [
                'audience' => 'admin',
                'category' => 'operations',
                'label' => 'Member message to admin',
                'variables' => ['title', 'subject', 'preview', 'member_name', 'action_url'],
                'supported' => $bellPush,
                'en' => [
                    'subject' => '{{title}}',
                    'body' => '{{subject}}: {{preview}}',
                ],
                'ar' => [
                    'subject' => '{{title}}',
                    'body' => '{{subject}}: {{preview}}',
                ],
            ],
        ];
    }

    /**
     * @return array<'member'|'admin', array<string, string>>
     */
    public static function optionsGroupedByAudience(): array
    {
        $groups = [
            'member' => [],
            'admin' => [],
        ];

        foreach (self::definitions() as $key => $definition) {
            $audience = $definition['audience'] ?? 'member';
            $groups[$audience][$key] = __($definition['label']);
        }

        return $groups;
    }

    public static function audienceFor(string $key): string
    {
        return self::definition($key)['audience'] ?? 'member';
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
            MemberOnboardingGreetingNotification::class => [
                'key' => 'member_onboarding_greeting',
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
            ReconciliationDigestNotification::class => [
                'key' => 'reconciliation_digest',
                'category' => 'automation',
            ],
            DelinquencyDigestNotification::class => [
                'key' => 'delinquency_digest',
                'category' => 'automation',
            ],
            ReconciliationExceptionRaisedNotification::class => [
                'key' => 'reconciliation_exception',
                'category' => 'automation',
            ],
            NewLoanApplicationNotification::class => [
                'key' => 'new_loan_application',
                'category' => 'operations',
            ],
            NewFundPostingNotification::class => [
                'key' => 'new_fund_posting',
                'category' => 'operations',
            ],
            NewCashOutRequestNotification::class => [
                'key' => 'new_cash_out_request',
                'category' => 'operations',
            ],
            NewMembershipApplicationNotification::class => [
                'key' => 'new_membership_application',
                'category' => 'operations',
            ],
            NewSupportRequestNotification::class => [
                'key' => 'new_support_request',
                'category' => 'operations',
            ],
            NewMemberRequestNotification::class => [
                'key' => 'new_member_request',
                'category' => 'operations',
            ],
            NewLoanEligibilityOverrideRequestNotification::class => [
                'key' => 'new_loan_eligibility_override',
                'category' => 'operations',
            ],
            LoanGuarantorTransferAdminNotification::class => [
                'key' => 'loan_guarantor_transfer_admin',
                'category' => 'operations',
            ],
            AdminDirectMessageNotification::class => [
                'key' => 'admin_direct_message',
                'category' => 'operations',
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
