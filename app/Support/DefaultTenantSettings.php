<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Canonical tenant Settings defaults (Samman production policy shape).
 *
 * Runtime fallbacks live in each {@see *Settings}::defaults() class. This helper
 * persists those defaults on fresh tenant provision so every Settings UI tab and
 * Automation → Schedule form matches the live Samman configuration without waiting
 * for a first save.
 *
 * Covered tabs: General, Collection (policy), Automation schedule, Loans, Guarantor
 * rules, Fiscal calendar, Public page, Statements, Communication, Notifications,
 * Reconciliation. Fund tiers / bank templates / SMS notification templates are seeded
 * separately ({@see DefaultFundAndLoanTiers}, {@see TenantDatabaseSeeder},
 * {@see NotificationTemplateCatalog}).
 *
 * Excluded from seeding: frozen business day, Twilio secrets, legacy migration
 * state, and operational halt flags.
 */
final class DefaultTenantSettings
{
    public const CURRENCY = 'SAR';

    public const CYCLE_START_DAY = 6;

    /**
     * Persist all Settings-page defaults for a fresh tenant database.
     */
    public static function seed(): void
    {
        $public = PublicPageSettings::defaults();

        Setting::set('general', 'currency', self::CURRENCY);
        Setting::set('general', 'fund_name', (string) $public['fund_name_en']);
        Setting::set('contribution', 'cycle_start_day', (string) self::CYCLE_START_DAY);

        // Business-day override off; banners off by default (Samman).
        Setting::set('general', BusinessDaySettings::KEY_BANNER_ADMIN, '0');
        Setting::set('general', BusinessDaySettings::KEY_BANNER_MEMBER, '0');

        // Automation → Schedule (job clocks, behaviour, notification suppressions)
        AutomationScheduleSettings::seedDefaults();
        ContributionPolicySettings::saveFromForm(ContributionPolicySettings::allForForm());
        ContributionAmountSettings::seedDefaults();

        // Loans + guarantor rules + loan queue projection
        LoanSettings::save(LoanSettings::defaults());
        LoanQueueProjectionSettings::saveFromForm(LoanQueueProjectionSettings::allForForm());

        // General / localization / ledger / member numbers
        LocalizationSettings::saveFromForm(LocalizationSettings::allForForm());
        LedgerSettings::saveFromForm(LedgerSettings::allForForm());
        MemberNumberSettings::save(MemberNumberSettings::defaults());

        // Fiscal calendar
        $fiscal = FiscalSettings::defaults();
        FiscalSettings::saveFromForm([
            'fiscal_year_start_month' => $fiscal['fiscal_year_start_month'],
            'fiscal_year_start_day' => $fiscal['fiscal_year_start_day'],
            'purge_policy' => $fiscal['purge_policy'],
            'current_fiscal_year_label' => $fiscal['current_fiscal_year_label'],
        ]);

        // Public page + Arabic display
        PublicPageSettings::save($public);
        ArabicDisplaySettings::save(ArabicDisplaySettings::defaults());

        // Statements
        StatementSettings::saveFromForm(StatementSettings::allForForm());

        // Communication + push events + brand (email chrome; not a Settings tab field today)
        CommunicationSettings::saveFromForm(CommunicationSettings::allForForm());
        CommunicationBrandSettings::saveFromForm(CommunicationBrandSettings::allForForm());
        PushEventSettings::saveFromForm(PushEventSettings::allForForm());

        // Notifications — channels off; credentials left empty
        NotificationSettings::save(NotificationSettings::defaults());

        // Reconciliation
        ReconciliationDigestSettings::saveFromForm(ReconciliationDigestSettings::allForForm());
        Setting::set('reconciliation', 'bank_variance_critical', '0');
        Setting::set('reconciliation', 'bank_statement_balance', '');
        Setting::set('reconciliation', 'bank_statement_date', '');
    }

    /**
     * Ensure settings exist after migrate (empty DB → full seed; otherwise backfill missing keys only).
     */
    public static function ensureInstalled(): void
    {
        if (! class_exists(Setting::class)) {
            return;
        }

        if (Setting::query()->count() === 0) {
            self::seed();

            return;
        }

        AutomationScheduleSettings::seedDefaults(onlyMissing: true);
        ContributionAmountSettings::seedDefaults();

        if (Setting::get('general', 'currency') === null) {
            Setting::set('general', 'currency', self::CURRENCY);
        }
        if (Setting::get('contribution', 'cycle_start_day') === null) {
            Setting::set('contribution', 'cycle_start_day', (string) self::CYCLE_START_DAY);
        }
        if (Setting::get('general', BusinessDaySettings::KEY_BANNER_ADMIN) === null) {
            Setting::set('general', BusinessDaySettings::KEY_BANNER_ADMIN, '0');
        }
        if (Setting::get('general', BusinessDaySettings::KEY_BANNER_MEMBER) === null) {
            Setting::set('general', BusinessDaySettings::KEY_BANNER_MEMBER, '0');
        }
    }
}
