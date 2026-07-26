<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Operator-facing catalog of notable tenant schema changes (newest first).
 * Shown on Audit & System → Maintenance → Recent database changes.
 */
final class TenantSchemaNotes
{
    /**
     * @return list<array{migration: string, title: string, body: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'migration' => '2026_07_24_194016_create_member_cash_transfer_requests_table',
                'title' => __('Member cash transfer requests'),
                'body' => __('Creates member_cash_transfer_requests for member-to-member cash transfer workflow (pending / accepted / rejected).'),
            ],
            [
                'migration' => '2026_07_24_181432_add_admin_transfer_fields_to_loans_table',
                'title' => __('Loans: Loan Transfer tracking columns'),
                'body' => __('Adds transferred_from_loan_id, admin_transfer_mode, and admin_transferred_at for Loan Transfer on Operations → Delinquency → Overdue.'),
            ],
            [
                'migration' => '2026_07_24_091514_create_portal_access_logs_table',
                'title' => __('Portal access logs'),
                'body' => __('Creates portal_access_logs for admin/member portal sign-in and access auditing (Audit & System → Access).'),
            ],
            [
                'migration' => '2026_07_23_075830_create_notification_templates_table',
                'title' => __('Notification templates'),
                'body' => __('Creates notification_templates for editable per-locale, per-channel notification bodies (Communications / Settings).'),
            ],
            [
                'migration' => '2026_07_18_123424_align_monthly_statement_balances_with_fund_ledger',
                'title' => __('Monthly statements: fund-ledger balance columns'),
                'body' => __('Aligns monthly statement balance fields with the fund ledger so statements match cash/fund reporting.'),
            ],
            [
                'migration' => '2026_07_16_081855_flip_fund_tier_loan_tier_relationship',
                'title' => __('Fund tiers ↔ loan tiers relationship flip'),
                'body' => __('Restructures fund_tiers / loan_tiers ownership so loan tiers belong under fund tiers (capacity model).'),
            ],
            [
                'migration' => '2026_07_10_084700_drop_contribution_cycle_allocations_table',
                'title' => __('Dropped contribution_cycle_allocations'),
                'body' => __('Removes the legacy contribution_cycle_allocations table after the allocation engine was rebuilt.'),
            ],
            [
                'migration' => '2026_07_08_153455_add_exclude_from_household_contribution_funding_to_members_table',
                'title' => __('Members: exclude from household contribution funding'),
                'body' => __('Adds exclude_from_household_contribution_funding for dependents/household funding rules.'),
            ],
            [
                'migration' => '2026_07_03_084550_add_threshold_waiver_fields_to_loans_and_installments',
                'title' => __('Loans: threshold installment waiver fields'),
                'body' => __('Adds waiver tracking on loans/installments for admin “Waive threshold installments” closures.'),
            ],
            [
                'migration' => '2026_07_03_082935_add_guarantor_name_and_application_form_to_loans_table',
                'title' => __('Loans: guarantor name and signed application form'),
                'body' => __('Adds guarantor_name and application_form_path for member loan applications before guarantor matching.'),
            ],
            [
                'migration' => '2026_07_03_074440_make_users_preferred_locale_nullable',
                'title' => __('Users: preferred locale nullable'),
                'body' => __('Allows preferred_locale to be null so localization can fall back to fund defaults.'),
            ],
            [
                'migration' => '2026_06_29_110015_add_member_fund_balance_at_disbursement_to_loans_table',
                'title' => __('Loans: member fund balance at disbursement'),
                'body' => __('Stores member_fund_balance_at_disbursement for split-funding / excess-fund cash-out calculations.'),
            ],
            [
                'migration' => '2026_06_28_143313_simplify_member_statuses_to_three_values',
                'title' => __('Members: simplified status enum'),
                'body' => __('Simplifies member status values after the membership lifecycle redesign (see member-status-spec).'),
            ],
            [
                'migration' => '2026_06_28_112156_add_frozen_at_to_members_table',
                'title' => __('Members: frozen_at'),
                'body' => __('Adds frozen_at for administrative freeze separate from withdrawn/terminated payout holds.'),
            ],
            [
                'migration' => '2026_06_25_075803_add_inactive_status_and_membership_flags_to_members_table',
                'title' => __('Members: inactive status and membership flags'),
                'body' => __('Adds inactive status support and contribution_cycles_active / related membership flags.'),
            ],
            [
                'migration' => '2026_06_20_120000_create_push_subscriptions_table',
                'title' => __('Web push subscriptions'),
                'body' => __('Creates push_subscriptions for admin browser push notifications (e.g. new loan applications).'),
            ],
            [
                'migration' => '2026_06_20_101720_create_support_request_replies_table',
                'title' => __('Support request replies'),
                'body' => __('Creates support_request_replies for threaded Help & FAQ ticket responses.'),
            ],
            [
                'migration' => '2026_06_20_101720_create_member_announcements_table',
                'title' => __('Member announcements'),
                'body' => __('Creates member_announcements for portal broadcast messages to members.'),
            ],
            [
                'migration' => '2026_06_20_101719_add_workflow_fields_to_support_requests_table',
                'title' => __('Support requests: workflow fields'),
                'body' => __('Adds status/workflow columns on support_requests for ticket handling.'),
            ],
            [
                'migration' => '2026_06_18_095939_add_late_repayment_fields_to_members_table',
                'title' => __('Members: late repayment tracking fields'),
                'body' => __('Adds late repayment counters used by delinquency / guarantor grace evaluation.'),
            ],
            [
                'migration' => '2026_06_13_172647_ensure_master_suspense_account_exists',
                'title' => __('Master Suspense account ensured'),
                'body' => __('Ensures the Master Suspense ledger account exists for reconciliation suspend postings.'),
            ],
            [
                'migration' => '2026_06_06_181500_create_sms_import_sessions_and_transactions_tables',
                'title' => __('SMS import sessions and transactions'),
                'body' => __('Creates sms_import_sessions and sms_transactions for SMS clearing workspace imports.'),
            ],
            [
                'migration' => '2026_06_06_180537_create_sms_import_templates_table',
                'title' => __('SMS import templates'),
                'body' => __('Creates sms_import_templates for SMS statement column mapping.'),
            ],
            [
                'migration' => '2026_06_06_154837_create_reconciliation_snapshots_table',
                'title' => __('Reconciliation snapshots'),
                'body' => __('Creates reconciliation_snapshots for daily/nightly reconciliation audit store.'),
            ],
            [
                'migration' => '2026_06_06_153205_create_notification_logs_table',
                'title' => __('Notification delivery logs'),
                'body' => __('Creates notification_logs for Audit & System → Notifications delivery history.'),
            ],
            [
                'migration' => '2026_06_06_153205_create_database_backups_table',
                'title' => __('Database backup history'),
                'body' => __('Creates database_backups rows tracked by System Maintenance backup history.'),
            ],
            [
                'migration' => '2026_06_06_152623_create_member_requests_table',
                'title' => __('Member requests'),
                'body' => __('Creates member_requests for portal change requests (freeze, reinstate, dependents, etc.).'),
            ],
            [
                'migration' => '2026_06_04_180000_add_loan_funding_strategy_fields',
                'title' => __('Loans: funding strategy fields'),
                'body' => __('Adds loan funding strategy / split fields used at approval and disbursement.'),
            ],
            [
                'migration' => '2026_06_04_172807_add_waived_status_to_contributions_table',
                'title' => __('Contributions: waived status'),
                'body' => __('Adds waived contribution status for arrears clearance without cash posting.'),
            ],
            [
                'migration' => '2026_06_04_170003_add_on_behalf_fields_to_membership_applications_table',
                'title' => __('Membership applications: on-behalf fields'),
                'body' => __('Adds on-behalf applicant fields for dependent / proxy membership applications.'),
            ],
            [
                'migration' => '2026_06_04_170002_create_dependent_allocation_changes_table',
                'title' => __('Dependent allocation changes'),
                'body' => __('Creates dependent_allocation_changes for household contribution allocation adjustments.'),
            ],
            [
                'migration' => '2026_06_04_082318_create_fiscal_closes_tables',
                'title' => __('Fiscal year close tables'),
                'body' => __('Creates fiscal_closes, fiscal_close_member_snapshots, and fiscal_close_waivers for year-end close.'),
            ],
            [
                'migration' => '2026_06_03_201755_create_loan_eligibility_override_requests_table',
                'title' => __('Loan eligibility override requests'),
                'body' => __('Creates loan_eligibility_override_requests for admin review of eligibility exceptions.'),
            ],
            [
                'migration' => '2026_06_03_094117_create_invest_disbursements_and_invest_returns_tables',
                'title' => __('Investment disbursements and returns'),
                'body' => __('Creates invest_disbursements and invest_returns for fund investment ledger flows.'),
            ],
            [
                'migration' => '2026_06_03_075751_create_fee_deductions_and_fee_disbursements_tables',
                'title' => __('Fee deductions and disbursements'),
                'body' => __('Creates fee_deductions and fee_disbursements for membership/fee cash movements.'),
            ],
            [
                'migration' => '2026_06_02_183735_create_expense_disbursements_table',
                'title' => __('Expense disbursements'),
                'body' => __('Creates expense_disbursements for operational expense payouts from the fund.'),
            ],
            [
                'migration' => '2026_06_02_173627_add_suspense_to_account_types',
                'title' => __('Account types: suspense'),
                'body' => __('Extends account types to include suspense for reconciliation hold accounts.'),
            ],
            [
                'migration' => '2026_05_31_170602_add_master_bank_and_fund_transaction_ids_to_bank_transactions_table',
                'title' => __('Bank transactions: master bank/fund linkage'),
                'body' => __('Adds master bank and fund transaction IDs on bank_transactions for clearing mirrors.'),
            ],
            [
                'migration' => '2026_05_31_100333_add_partially_disbursed_and_transferred_to_loan_status_enum',
                'title' => __('Loans: partially_disbursed and transferred statuses'),
                'body' => __('Extends loan status enum for partial disbursement and transferred loans.'),
            ],
            [
                'migration' => '2026_05_31_092636_create_cash_out_requests_table',
                'title' => __('Cash-out requests'),
                'body' => __('Creates cash_out_requests for member/admin cash payout workflow and bank clearing.'),
            ],
        ];
    }
}
