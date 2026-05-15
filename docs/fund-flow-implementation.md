# Fund Flow Implementation

This document describes the fundamental fund flow, lifecycle, and account treatments implemented in the FundFlow application, based on the rules defined in `prompts.txt`.

## Account Structure

| Account | Type | Purpose |
|---------|------|---------|
| Master Bank | Master | Receives imported bank statement transactions |
| Master Cash | Master | Mirrors selected bank transactions; reflects total cash at hand |
| Master Fund | Master | Reflects total fund value (contributions + repayments − disbursements) |
| Member Cash | Per-member | Individual member's cash balance |
| Member Fund | Per-member | Individual member's fund balance (can go negative with active loan) |

## Fund Flow Lifecycle

### 1. Bank Statement Import

Members transfer money to external bank accounts. The admin regularly imports bank transaction statements (CSV) into the Master Bank account.

- CSV format is configurable via **Settings → Bank Import** (delimiter, date format, column mapping, header/skip rows)
- Duplicate detection via transaction hash (date + description + amount + reference)
- Each import creates a `BankStatement` record tracking filename, row counts, and status

**Service:** `BankImportService::importCsv()`

### 2. Mirror to Master Cash

The admin selects imported bank transactions and mirrors them to the Master Cash account. Both Master Bank and Master Cash are updated.

- Credits (positive amounts): contributions, deposits, repayments
- Debits (negative amounts): loan disbursements

**Service:** `FundFlowService::mirrorToCash()`  
**Status transition:** `imported` → `mirrored`

### 3. Post to Member Cash

The admin posts mirrored transactions to individual member cash accounts. This reflects the credit/debit in the member's cash account as a mirror — no actual debit of Master Cash occurs.

**Service:** `FundFlowService::postToMember()`  
**Status transition:** `mirrored` → `posted`

### 4. Contribution Cycle

A contribution cycle starts on a configurable day of the month (default: 6th, via **Settings → Contributions**) and ends the day before the next cycle starts.

When the admin runs the contribution cycle collection:

1. **Debit** Member Cash account
2. **Credit** Member Fund account
3. **Mirror (credit)** Master Fund account

Only one contribution or repayment per member per cycle is allowed.

**Service:** `ContributionService::postContribution()`

### 5. Loan Disbursement

When a loan is disbursed to a member:

1. **Debit** Master Fund (full disbursement amount)
2. **Debit** Member Fund (same amount — can go negative, indicating outstanding loan)
3. **Credit** Member Cash

**Service:** `LoanService::disburseLoan()`

### 6. Loan Payout

When the loan amount is actually paid out (bank transfer to member):

1. **Debit** Member Cash
2. **Debit** Master Cash (mirror)

This is matched by a future bank statement import showing the outgoing transfer.

**Service:** `LoanService::payoutLoan()`

### 7. Loan Repayment

Repayments are processed via the contribution cycle:

1. **Debit** Member Cash
2. **Credit** Member Fund
3. **Mirror (credit)** Master Fund
4. Update loan `total_repaid`; mark completed when fully repaid

**Service:** `LoanService::recordRepayment()`

## Account Balance Rules

- **Master Fund** = sum of all contributions + repayments − loan disbursements
- **Master Cash** = sum of all member cash accounts (increases/decreases with bank imports)
- **Member Fund** may go negative (indicates outstanding loan to be repaid)
- Members may have only one active loan at any given time

## Database Tables

### `bank_statements`

Tracks each imported CSV file.

| Column | Type | Description |
|--------|------|-------------|
| filename | string | Original CSV filename |
| statement_date | date | Derived from latest transaction date |
| bank_name | string | Optional bank identifier |
| total_rows | int | Total data rows in CSV |
| imported_rows | int | Successfully imported rows |
| duplicate_rows | int | Skipped duplicate rows |
| status | enum | `pending`, `processing`, `completed`, `failed` |
| imported_by | FK → users | Admin who performed the import |
| imported_at | timestamp | When the import occurred |

### `bank_transactions`

Individual transactions from imported bank statements.

| Column | Type | Description |
|--------|------|-------------|
| bank_statement_id | FK → bank_statements | Parent statement |
| transaction_date | date | Transaction date |
| description | string | Transaction description |
| amount | decimal(15,2) | Positive = credit, negative = debit |
| reference | string | Transaction reference/check number |
| status | enum | `imported`, `mirrored`, `posted`, `ignored` |
| member_id | FK → members | Assigned member (set on posting) |
| hash | string (unique) | Duplicate detection hash |
| raw_data | text | Original CSV row as JSON |

## Settings

| Group | Key | Default | Description |
|-------|-----|---------|-------------|
| contribution | cycle_start_day | 6 | Day of month when cycle starts (1–28) |
| bank | csv_template | JSON | CSV parsing configuration (delimiter, date format, columns, etc.) |

## Admin Workflow (Filament UI)

### Banking → Bank Statements

- **Import Statement** action: upload CSV, optionally specify bank name
- View statement details and its transactions

### Banking → Bank Transactions

- **Mirror to Cash** (row/bulk): move imported transactions to mirrored status, update Master Bank and Master Cash
- **Post to Member** (row): assign a mirrored transaction to a member, update their cash account
- **Ignore** (row/bulk): mark transactions as ignored

### Fund Management → Contributions

- **Generate Monthly**: create pending contributions for all active members
- **Post** (row/bulk): run the contribution cycle transfer

### Loans

- **Approve** → **Disburse** → **Payout**: three-step loan lifecycle
- Loan payout is a separate step from disbursement to separate the accounting entry from the actual bank transfer
