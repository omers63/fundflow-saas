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

When a loan is disbursed to a member (ledger only — no bank payout yet):

1. **Debit** Loan account (principal)
2. **Debit** Master Fund `(master funded)`
3. **Debit** Member Fund `(member mirror)` — may go negative
4. **Credit** Master Cash `(cash payout mirror)`
5. **Credit** Member Cash `(cash payout)`

Proceeds stay in the member cash account until the member submits a **cash-out request**. Master Cash and Member Cash must move together so `MasterAccountInvariantService` stays balanced.

**Service:** `LoanService::disburseLoan()` → `LoanLedgerService::postPartialLoanDisbursement()`

### 6. Member Cash-Out

When a member requests withdrawal and admin **accepts**:

1. **Debit** Member Cash `(cash out)`
2. **Debit** Master Cash `(cash out mirror)`
3. Create an **uncleared** `BankTransaction` (negative amount) linked to the request

**Clearance (later):** Admin matches the uncleared line to an imported bank statement row (**Bank Accounts → Statement lines → Clear / Match**). No extra ledger entries on match — only `is_cleared` flags update.

**Services:** `MemberCashOutService::submit()` / `accept()` / `clearTransaction()`

### 7. Loan Repayment

Repayments are processed via the contribution cycle:

1. **Debit** Member Cash
2. **Credit** Member Fund
3. **Mirror (credit)** Master Fund
4. Update loan `total_repaid`; mark completed when fully repaid

**Service:** `LoanService::recordRepayment()`

## Account Balance Rules

- **Master Fund** = sum of all contributions + repayments − loan disbursements (fund leg)
- **Master Cash** = sum of all member cash account balances (keep member/master cash credits and debits paired)
- **Member Fund** may go negative (indicates outstanding loan to be repaid)
- Members may have only one active loan at any given time
- **Bank clearance** reconciles ledger intent to imported bank lines; it does not replace paired master/member postings

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

See also [master-bank-manual-credits-and-fund-flow.md](./master-bank-manual-credits-and-fund-flow.md) for manual master bank ledger entries vs statement import / fund posting workflows.

### Banking → Bank Accounts

- **Import statement** (page header, any tab): upload CSV, choose template, optionally specify bank name
- **Statements** tab: view past imports and open statement details
- **Statement lines** tab: work imported rows (mirror, post to member)

### Banking → Bank Transactions

- **Mirror to Cash** (row/bulk): move imported transactions to mirrored status, update Master Bank and Master Cash
- **Post to Member** (row): assign a mirrored transaction to a member, update their cash account
- **Ignore** (row/bulk): mark transactions as ignored

### Fund Management → Contributions

- **Generate Monthly**: create pending contributions for all active members
- **Post** (row/bulk): run the contribution cycle transfer

### Fund Management → Deposits / Cash outs

- **Deposits**: member submits → admin accept credits **master cash + member cash** → uncleared bank line until matched
- **Cash outs**: member submits → admin accept debits **master cash + member cash** → uncleared bank line until matched

### Loans

- **Approve** → **Disburse**: fund debits + paired cash credits (member + master)
- **Cash out** (member portal): separate step when the member wants funds sent to their bank
- **Mark bank payout** (`payoutLoan()`): optional timestamp only; ledger payout is via cash-out + bank clearance
