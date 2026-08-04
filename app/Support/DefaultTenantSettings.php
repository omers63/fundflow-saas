<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical tenant Settings defaults (Samman production policy shape).
 *
 * Runtime fallbacks live in each {@see *Settings}::defaults() class. This helper
 * persists those defaults on fresh tenant provision so every Settings UI tab and
 * Automation → Schedule form matches the live Samman configuration without waiting
 * for a first save.
 *
 * Also seeds Settings-adjacent catalog data for a complete Settings page:
 * fund/loan tiers ({@see DefaultFundAndLoanTiers}), bank import templates
 * ({@see DefaultBankImportTemplates}), and missing notification templates
 * ({@see NotificationTemplateCatalog}).
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

        self::seedCatalogData();
    }

    /**
     * Ensure settings exist after migrate (empty DB → full seed; otherwise backfill missing keys only).
     */
    public static function ensureInstalled(): void
    {
        if (! class_exists(Setting::class)) {
            return;
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        if (Setting::query()->count() === 0) {
            self::seed();

            return;
        }

        AutomationScheduleSettings::seedDefaults(onlyMissing: true);
        ContributionAmountSettings::seedDefaults();
        self::seedMissingPolicySettings();

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

        // Always safe for catalog tables: insert when empty / missing rows only.
        self::seedCatalogData();
    }

    /**
     * Settings-adjacent catalog data shared by seed and ensureInstalled.
     */
    public static function seedCatalogData(): void
    {
        DefaultFundAndLoanTiers::seedIfEmpty();
        DefaultBankImportTemplates::seedIfMissing();

        if (Schema::hasTable('notification_templates')) {
            NotificationTemplateCatalog::seedMissingDefaults();
        }
    }

    /**
     * Backfill key groups that older tenants may never have written after first install.
     */
    private static function seedMissingPolicySettings(): void
    {
        self::seedMissingGroupKeys(LoanSettings::GROUP, LoanSettings::defaults());
        self::seedMissingGroupKeys(LoanQueueProjectionSettings::GROUP, LoanQueueProjectionSettings::defaults());
        self::seedMissingGroupKeys(LedgerSettings::GROUP, LedgerSettings::defaults());
        self::seedMissingGroupKeys(LocalizationSettings::GROUP, LocalizationSettings::defaults());
        self::seedMissingGroupKeys(MemberNumberSettings::GROUP, MemberNumberSettings::defaults());
        self::seedMissingGroupKeys(PublicPageSettings::GROUP, PublicPageSettings::defaults());
        self::seedMissingGroupKeys(StatementSettings::GROUP, StatementSettings::defaults());
        self::seedMissingGroupKeys(CommunicationSettings::GROUP, CommunicationSettings::defaults());
        self::seedMissingGroupKeys(CommunicationBrandSettings::GROUP, CommunicationBrandSettings::defaults());
        self::seedMissingGroupKeys(PushEventSettings::GROUP, PushEventSettings::defaults());
        self::seedMissingGroupKeys(NotificationSettings::GROUP, NotificationSettings::defaults());
        self::seedMissingGroupKeys(ReconciliationDigestSettings::GROUP, ReconciliationDigestSettings::defaults());
        self::seedMissingGroupKeys(FiscalSettings::GROUP, FiscalSettings::defaults());
        self::seedMissingGroupKeys(ArabicDisplaySettings::GROUP, ArabicDisplaySettings::defaults());

        self::seedMissingGroupKeys(ContributionPolicySettings::GROUP_DELINQUENCY, ContributionPolicySettings::delinquencyDefaults());
        self::seedMissingGroupKeys(ContributionPolicySettings::GROUP_COLLECTION, ContributionPolicySettings::collectionDefaults());
        self::seedMissingGroupKeys(ContributionPolicySettings::GROUP_LATE_FEE, ContributionPolicySettings::lateFeeDefaults());
        self::seedMissingGroupKeys(ContributionPolicySettings::GROUP_SUBSCRIPTION, ['annual_fee' => 0]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private static function seedMissingGroupKeys(string $group, array $defaults): void
    {
        $stored = Setting::getGroup($group);

        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== '') {
                continue;
            }

            if (Setting::get($group, $key) !== null) {
                continue;
            }

            Setting::set($group, $key, self::serializeDefaultValue($value));
        }
    }

    private static function serializeDefaultValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        return (string) $value;
    }
}
