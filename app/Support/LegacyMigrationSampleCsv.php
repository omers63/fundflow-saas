<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Coherent sample CSV payloads for the legacy migration wizard.
 * Member, loan, and payment rows reference the same member numbers, names, and emails.
 */
final class LegacyMigrationSampleCsv
{
    /**
     * @return list<string>
     */
    public static function memberHeaders(): array
    {
        return [
            'member_number',
            'name',
            'email',
            'phone',
            'monthly_contribution_amount',
            'joined_at',
            'status',
            'parent_member_number',
            'contribution_arrears_cutoff_date',
            'cutoff_cash_balance',
            'cutoff_fund_balance',
            'password',
            'gender',
            'marital_status',
            'national_id',
            'date_of_birth',
            'city',
            'address',
            'mobile_phone',
            'home_phone',
            'work_phone',
            'work_place',
            'residency_place',
            'occupation',
            'employer',
            'monthly_income',
            'bank_account_number',
            'iban',
            'next_of_kin_name',
            'next_of_kin_phone',
            'application_fee_amount',
            'application_fee_transfer_date',
            'application_fee_transfer_reference',
            'applicant_message',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function memberRows(): array
    {
        return [
            [
                'MEM-1001',
                'Ahmed Al Saud',
                'ahmed.import@example.test',
                '0501000101',
                '500',
                '2024-06-01',
                'active',
                '',
                '2025-12-31',
                '1500',
                '8000',
                '',
                'male',
                'married',
                '1000000001',
                '1989-01-14',
                'Riyadh',
                'King Fahd Rd, Building 12',
                '0501000101',
                '0114000101',
                '0114001101',
                'Aramco HQ',
                'Riyadh',
                'Engineer',
                'Aramco',
                '18500',
                '101000000001',
                'SA030000000000101000000001',
                'Mona Al Saud',
                '0503000101',
                '75',
                '2024-05-20',
                'FEE-AHMED-001',
                'Migrated from legacy system',
            ],
            [
                'MEM-1002',
                'Fatimah Hassan',
                'fatimah.import@example.test',
                '0501000102',
                '1000',
                '2025-01-15',
                'active',
                'MEM-1001',
                '2025-12-31',
                '0',
                '0',
                '',
                'female',
                'married',
                '1000000002',
                '1991-03-22',
                'Riyadh',
                'Olaya St, Apt 8',
                '0501000102',
                '',
                '',
                'King Faisal Hospital',
                'Riyadh',
                'Doctor',
                'KFSH',
                '24000',
                '101000000002',
                'SA030000000000101000000002',
                'Hassan Ali',
                '0503000102',
                '',
                '',
                '',
                '',
            ],
            [
                'MEM-1003',
                'Omar Mansour',
                'omar.import@example.test',
                '0501000103',
                '1500',
                '2024-03-01',
                'active',
                '',
                '2025-12-31',
                '250',
                '4200',
                '',
                'male',
                'single',
                '1000000003',
                '1995-06-17',
                'Jeddah',
                'Prince Sultan St',
                '0501000103',
                '',
                '0124500103',
                '',
                'Jeddah',
                '',
                '',
                '',
                '101000000003',
                'SA030000000000101000000003',
                'Nasser Omar',
                '0503000103',
                '',
                '',
                '',
                '',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function loanHeaders(): array
    {
        return [
            'loan_id',
            'loan_status',
            'member_number',
            'member_name',
            'amount_approved',
            'member_portion',
            'master_portion',
            'disbursed_at',
            'installments_count',
            'paid_installments_count',
            'total_amount_repaid',
            'guarantor_member_number',
            'guarantor_name',
            'purpose',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function loanRows(): array
    {
        return [
            ['262', 'active', '', 'Omar Mansour', '12000', '4800', '7200', '2025-08-07', '12', '5', '5500', 'MEM-1002', 'Fatimah Hassan', 'Active loan — borrower matched by member_name; guarantor by number and name'],
            ['263', 'active', 'MEM-1001', '', '4000', '4000', '0', '2025-11-04', '6', '1', '700', '', '', 'Small emergency loan — borrower matched by member_number'],
            ['264', 'completed', 'MEM-1001', '', '10000', '3500', '6500', '2022-02-15', '10', '10', '10000', 'MEM-1002', '', 'Historical loan — guarantor by member_number only'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function paymentHeaders(): array
    {
        return [
            'member_number',
            'member_name',
            'payment_date',
            'amount',
            'payment_type',
            'period',
            'notes',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function paymentRows(): array
    {
        return [
            ['MEM-1001', '', '2025-10-05', '500', '', '', 'Blank payment_type — classifier will suggest contribution (matches monthly amount)'],
            ['MEM-1002', 'Fatimah Hassan', '2025-11-01', '1000', 'contribution', '2025-11', 'Explicit contribution for November'],
            ['', 'Omar Mansour', '2025-09-15', '750', 'loan_repayment', '', 'Borrower matched by member_name only'],
            ['MEM-1001', '', '2024-06-01', '500', 'ignore', '2024-06', 'Before cut-off — skipped in snapshot strategy; use ignore when excluding history'],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function memberColumnDocs(): array
    {
        return [
            ['member_number', true, __('Stable ID from old system (recommended for every row)')],
            ['name', true, __('Full name')],
            ['email', true, __('Unique login email')],
            ['phone', false, __('Contact phone')],
            ['monthly_contribution_amount', false, __('500–3000 in steps of 500 (default 500)')],
            ['joined_at', false, __('YYYY-MM-DD')],
            ['status', false, __('active, inactive, withdrawn — legacy labels (delinquent, suspended, terminated, Arabic equivalents) are mapped on import')],
            ['parent_member_number', false, __('Household parent member number (parent row may appear anywhere in the file)')],
            ['contribution_arrears_cutoff_date', false, __('Same as migration cut-off (per row or modal default); required when posting cut-off balances')],
            ['cutoff_cash_balance', false, __('Member cash wallet at cut-off (alias: opening_cash_balance)')],
            ['cutoff_fund_balance', false, __('Member fund pool share at cut-off (alias: opening_fund_balance)')],
            ['password', false, __('Portal password (≥8 chars); otherwise uses default from the upload form')],
            ['gender', false, __('male, female, other — populates member profile')],
            ['marital_status', false, __('single, married, divorced, widowed — populates member profile')],
            ['national_id', false, __('National ID / Iqama — populates member profile')],
            ['date_of_birth', false, __('YYYY-MM-DD or d/m/Y — populates member profile')],
            ['city', false, __('City — populates member profile')],
            ['address', false, __('Street address — populates member profile')],
            ['mobile_phone', false, __('Mobile phone — populates member profile (fills phone when phone is empty)')],
            ['home_phone', false, __('Home phone — populates member profile')],
            ['work_phone', false, __('Work phone — populates member profile')],
            ['work_place', false, __('Work place — populates member profile')],
            ['residency_place', false, __('Residency place — populates member profile')],
            ['occupation', false, __('Occupation — populates member profile')],
            ['employer', false, __('Employer — populates member profile')],
            ['monthly_income', false, __('Monthly income amount — populates member profile')],
            ['bank_account_number', false, __('Bank account number — populates member profile')],
            ['iban', false, __('IBAN — populates member profile')],
            ['next_of_kin_name', false, __('Next of kin name — populates member profile')],
            ['next_of_kin_phone', false, __('Next of kin phone — populates member profile')],
            ['application_fee_amount', false, __('Application fee amount (alias: membership_fee_amount)')],
            ['application_fee_transfer_date', false, __('Application fee transfer date (alias: membership_fee_transfer_date)')],
            ['application_fee_transfer_reference', false, __('Application fee transfer reference (alias: membership_fee_transfer_reference)')],
            ['applicant_message', false, __('Applicant message (alias: message)')],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function loanColumnDocs(): array
    {
        return [
            ['loan_id', false, __('Legacy loan ID from the old system — preserved on import and used when classifying repayments')],
            ['loan_status', true, __('active for open loans; completed or early_settled when fully repaid')],
            ['member_number / member_name', true, __('Borrower — one identifier required; must match an imported member row')],
            ['amount_approved', true, __('Original approved principal')],
            ['member_portion / master_portion', false, __('Funding split; may be omitted and inferred from fund balance')],
            ['disbursed_at', false, __('YYYY-MM-DD disbursement date')],
            ['installments_count', false, __('Total EMI count when known')],
            ['paid_installments_count', false, __('EMIs already paid before cut-off')],
            ['total_amount_repaid', false, __('Sum repaid to date (required when paid_installments_count > 0)')],
            ['guarantor_member_number / guarantor_name', false, __('Guarantor — use number, name, or both (alias: guarantor_number)')],
            ['purpose', false, __('Free-text loan description')],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function paymentColumnDocs(): array
    {
        return [
            ['member_number / member_name', true, __('Who paid — must match imported members')],
            ['payment_date', true, __('YYYY-MM-DD')],
            ['amount', true, __('Payment amount')],
            ['payment_type', false, __('contribution, loan_repayment, ignore — leave blank to auto-classify')],
            ['period', false, __('YYYY-MM for contributions (defaults from payment_date when blank)')],
            ['notes', false, __('Free text from old system')],
        ];
    }
}
