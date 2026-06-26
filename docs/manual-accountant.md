# FundFlow Accountant — Diagnostic & Repair Manual

This manual is for **accountants and reconciliation officers** who investigate balance drift, reconciliation exceptions, bank clearance gaps, legacy migration issues, and ledger integrity on a FundFlow tenant.

**Admin URL:** `https://<your-fund-domain>/admin`  
**Primary workspaces:** Finance → Reconciliation, Bank clearing, Reports; System → Audit & System.

Companion references: [fund-flow-one-pager-accountants.md](fund-flow-one-pager-accountants.md), [fund-flow-dynamics-accountants.md](fund-flow-dynamics-accountants.md), [fund-flow-dynamics.md](fund-flow-dynamics.md), [fund-flow-reconciliation-and-scheduler.md](fund-flow-reconciliation-and-scheduler.md).

---

## Table of contents

1. [How FundFlow accounting is structured](#1-how-fundflow-accounting-is-structured)
2. [Reconciliation layers](#2-reconciliation-layers)
3. [Daily and monthly schedule](#3-daily-and-monthly-schedule)
4. [Investigation workflow](#4-investigation-workflow)
5. [Pool invariants](#5-pool-invariants)
6. [Member drift formulas](#6-member-drift-formulas)
7. [Reconciliation exception playbook](#7-reconciliation-exception-playbook)
8. [Bank clearing diagnostics](#8-bank-clearing-diagnostics)
9. [Ledger tools](#9-ledger-tools)
10. [Correction actions in the UI](#10-correction-actions-in-the-ui)
11. [Artisan command runbook](#11-artisan-command-runbook)
12. [Legacy migration repair](#12-legacy-migration-repair)
13. [Year-end close gates](#13-year-end-close-gates)
14. [Reports and snapshots](#14-reports-and-snapshots)
15. [When batch posting is halted](#15-when-batch-posting-is-halted)
16. [Symptom → entry point quick table](#16-symptom--entry-point-quick-table)

---

## 1. How FundFlow accounting is structured

### 1.1 Chart of accounts

| Account | Role | Pool mirror |
|---------|------|-------------|
| Master Bank | Imported statement lines | — |
| Master Cash | Pool cash on hand | Must equal Σ member cash |
| Master Fund | Pool equity | Must equal Σ member fund |
| Member Cash | Per-member wallet | Mirrored to master cash |
| Member Fund | Per-member equity | Mirrored to master fund |
| Loan sub-account | Principal per loan | Per loan |
| Master Fees | Fee income | Master only |

### 1.2 Two ledgers, one economic event

**Cash** and **fund** move independently until an operation links them (e.g. contribution collection moves cash → fund).

### 1.3 Intent vs clearance

| Stage | What it records |
|-------|-----------------|
| **Ledger posting** | Economic intent (deposit accepted, cash-out approved, disbursement) |
| **Bank clearance** | Match to imported statement line — updates linkage, **not** a second cash posting when done correctly |

Reference flows: [fund-flow-dynamics.md](fund-flow-dynamics.md) §2–5.

---

## 2. Reconciliation layers

FundFlow uses **three** complementary mechanisms:

```mermaid
flowchart LR
    subgraph realtime["Realtime (every Transaction)"]
        RT1["Journal balanced?"]
        RT2["Member drift"]
        RT3["Master pool drift"]
        RT4["Late-fee exemptions"]
    end

    subgraph snap["Audit snapshots"]
        S1["fund:reconcile --daily"]
        S2["fund:reconcile --monthly"]
        S3["ReconciliationSnapshot table"]
    end

    subgraph batch["Control batch"]
        B1["fund:nightly-reconciliation"]
        B2["Domain sweeps"]
        B3["Auto-resolve"]
        B4["BatchPostingGate"]
    end

    TXN["Transaction posted"] --> realtime
    CRON["Scheduler 06:00–08:00"] --> snap
    CRON --> batch
    batch --> EXC["ReconciliationException queue"]
```

| Layer | Command / trigger | Output |
|-------|-------------------|--------|
| **Realtime** | `TransactionObserver` → `ReconciliationService::onTransactionPosted()` | Immediate exceptions; audit log |
| **Snapshots** | `fund:reconcile` | `reconciliation_snapshots` — historical audit PDF |
| **Nightly batch** | `fund:nightly-reconciliation` | Full exception sweep + auto-resolve + halt gate |

**Important:** Snapshots are for **audit history**. The **exception queue** is for **active remediation**.

---

## 3. Daily and monthly schedule

All commands run **per tenant** (`--tenants=slug` when run manually).

| Time | Command | Accountant focus |
|------|---------|------------------|
| 06:00 | `fund:assert-master-invariants` | Quick Σ master vs members |
| 06:20 | `fund:reconcile --daily` | Yesterday’s audit snapshot |
| 06:30 | `fund:nightly-reconciliation` | Exception queue refreshed |
| 07:15 | `contributions:apply-late-fees` | Fee posting review |
| 08:00 | `bank:auto-match` | Unmatched lines reduced |

Monthly: `fund:reconcile --monthly` on 2nd; contribution/EMI cycle jobs on 1st–6th (see [manual-administrator.md](manual-administrator.md) §19).

**Jobs UI:** Audit & System → Jobs — manual re-run with history in `SystemJobRun`.

---

## 4. Investigation workflow

Standard loop for any reported balance issue:

```
1. Identify symptom (member complaint, exception badge, pool health widget)
2. Open Reconciliation → Queue (or member Transactions tab)
3. Read exception code + presenter guidance
4. Trace reference transaction(s) in audit log
5. Verify bank clearing state if cash-related
6. Apply correction (journal, reverse, reclassify, or CLI repair)
7. Re-run snapshot or wait for nightly batch
8. Confirm exception resolved and invariants pass
```

### 4.1 Data to collect before changing anything

| Question | Where to look |
|----------|---------------|
| Which member? | Member → Transactions (cash/fund tabs) |
| Which account type? | Cash vs fund vs loan |
| When did it start? | Activity date, migration date |
| Open exceptions? | Reconciliation → Queue |
| Uncleared bank lines? | Bank clearing → Work queue (`operations`) |
| Recent admin action? | Audit & System → Audit log |
| Stored balance vs txn sum? | `accounting:rebuild-balances --dry-run` |

### 4.2 Do not skip dry-run

For balance rebuild and legacy repair commands, always run **`--dry-run`** first when available.

---

## 5. Pool invariants

### 5.1 Master pool

**Service:** `MasterAccountInvariantService`  
**CLI:** `php artisan fund:assert-master-invariants --tenants=<slug>`

| Check | Formula |
|-------|---------|
| Master cash | `master_cash.balance` = Σ `member_cash.balance` |
| Master fund | `master_fund.balance` = Σ `member_fund.balance` |

| Exception code | Severity |
|----------------|----------|
| `MASTER_CASH_POOL_DRIFT` | high |
| `MASTER_FUND_POOL_DRIFT` | high |
| `MASTER_IMBALANCE_UNRESOLVED` | **critical** (halts batch posting) |

**Note:** Realtime pool checks **skip** while `AccountingService::masterPoolMirrorInProgress()` — paired mirrors post master and member legs in one logical operation.

### 5.2 Tiny drift rounding

Nightly batch may post **rounding suspense** adjustments when drift is within configured tolerance (`Settings → Reconciliation`).

---

## 6. Member drift formulas

**Service:** `MemberInvariantService`

| Code | Meaning |
|------|---------|
| `MEMBER_CASH_DRIFT` | Stored cash ≠ component formula (deposits, disbursements, collections, repayments, cash-outs, etc.) |
| `MEMBER_FUND_DRIFT` | Stored fund ≠ component formula (contributions, loan fund legs, opening balances, guarantor debits, etc.) |

**Investigation:**

1. Open member → **Transactions** → cash or fund tab.
2. Sum movements by type; compare to expected formula (see spec in workspace rules / fund-flow accountants doc).
3. If stored `accounts.balance` ≠ sum of `transactions` lines → run balance rebuild (§11).

Common causes: deleted transactions without balance adjustment; legacy import repair; manual SQL outside application.

---

## 7. Reconciliation exception playbook

**UI:** Reconciliation → **Queue**  
**Presenter copy:** `ReconciliationExceptionPresenter` (titles and remediation hints in UI)

### 7.1 Master / journal

| Code | Typical cause | First action |
|------|---------------|--------------|
| `UNBALANCED_ENTRY` | DR ≠ CR on journal | Reverse or post balancing leg |
| `MASTER_IMBALANCE_UNRESOLVED` | Master ≠ Σ members beyond tolerance | Fix root drift; clear halt |
| `MASTER_*_POOL_DRIFT` | Mirror missing or partial | Find txn without paired master leg |
| `MEMBER_*_DRIFT` | Component vs stored | Trace member ledger; rebuild balances |

### 7.2 Contributions

| Code | Typical cause | First action |
|------|---------------|--------------|
| `PENDING_PAST_WINDOW_CLOSE` | Workflow: still pending after cycle close | Collection ops — not always ledger bug |
| `COLLECTED_WITHOUT_POST` | Status collected, no txn | Post or reverse status |
| `CONTRIBUTION_EXEMPT_COLLECTED` | Exempt member collected | Reverse collection |
| `DUPLICATE_CONTRIBUTION_DEBIT` | Double debit | Reverse duplicate |
| `ORPHAN_MASTER_FUND_CREDIT` | Master leg without member | Add member leg or reverse |
| `CONTRIBUTION_MISSING_MASTER_CREDIT` | Member leg without master mirror | Post mirror |
| `CONTRIBUTION_AMOUNT_MISMATCH` | Due ≠ collected | Adjust or reclassify |

### 7.3 Loans / EMI

| Code | Typical cause | First action |
|------|---------------|--------------|
| `ACTIVE_BEFORE_FULL_DISBURSE` | Status vs disbursement | Fix loan status or post disbursement |
| `DISBURSEMENT_MEMBER_CASH_MISSING` | Disbursement without cash credit | Post cash leg |
| `EMI_COLLECTED_LEDGER_MISSING` | Installment paid, no txn | Post EMI collection |
| `EMI_MISSED_SUFFICIENT_CASH` | Cash available but EMI not collected | Run collection or manual EMI post |
| `EMI_OVER_COLLECTION` | Paid more than installment | Reclassify excess |
| `GUARANTOR_BORROWER_DUPLICATE_DEBIT` | Double guarantor charge | Reverse duplicate |

### 7.4 Bank clearing

| Code | Typical cause | First action |
|------|---------------|--------------|
| `RECON_UNMATCHED_BANK_LINE` | Import not matched | Bank clearing → match |
| `RECON_AMBIGUOUS_MATCH` | Multiple candidates | Manual match |
| `UNMATCHED_CASH_ENTRY` | Operational line stale | Match or clear |
| `CASH_DEPOSIT_UNBANKED` | Accepted deposit, no bank evidence | Import + match |
| `AMOUNT_MISMATCH` | Linked amounts differ | Adjust link or posting |
| `STALE_PENDING` | Old uncleared line | Review and match/ignore |

### 7.5 Fees

| Code | Typical cause | First action |
|------|---------------|--------------|
| `FEE_WRONG_TIER` | Late fee tier mismatch | Reapply correct tier |
| `REPLACEMENT_PRIOR_TIER_NOT_REVERSED` | Replacement model incomplete | Reverse prior fee leg |
| `FEE_INCOME_DRIFT` | Master fees ≠ posted fees | Trace fee transactions |

### 7.6 UI actions on exceptions

Available per code (see `ReconciliationExceptionActions`):

- **Retry auto-resolve**
- **Assign / Escalate / Write off / Accept override**
- **Custom journal / Post correction / Post cash correction**
- **Reverse transaction**
- **Reclassify** (contribution ↔ repayment)

---

## 8. Bank clearing diagnostics

**Workspace:** Finance → Bank clearing

### 8.1 Queue filters

| Filter | Shows |
|--------|-------|
| All | Full queue |
| From bank file | Imported statement lines needing action |
| From operations | Uncleared deposit/cash-out placeholders |

### 8.2 Expected lifecycle

```
Deposit accept     → uncleared operational line → import CSV → match → cleared
Cash-out accept    → uncleared operational line → bank transfer → match → cleared
Bank import only   → mirror to cash → post to member → (optional) auto-collection
```

### 8.3 Common mistakes

| Mistake | Symptom |
|---------|---------|
| Same deposit via Path A + Path B | Double cash credit |
| Clearance treated as new posting | Duplicate cash legs |
| Match wrong amount/date | `AMOUNT_MISMATCH` |

**Services:** `BankClearingQueueService`, `BankClearingMatchService`, `BankTransactionClearanceService`

---

## 9. Ledger tools

### 9.1 Member ledger

**Members → {member} → Transactions** — cash, fund, loan tabs with running context.

### 9.2 Master accounts

**URL:** `/admin/master-accounts` — master cash, fund, bank, fees with transaction relation managers.

**Manual adjustments** (master only): Credit / Debit / Refund header actions on account transaction managers.

### 9.3 Audit trail

**Audit & System → Audit log** — filter by reconciliation, loans, admin overrides.

Export via Reports → audit export.

### 9.4 Imported loan repayments

**Loans → {loan} → Imported/legacy repayments** — historical migration repayment rows (paid at + amount). Compare sum to `master_portion` / `repaid_to_master` on loan.

---

## 10. Correction actions in the UI

| Tool | Location | Use |
|------|----------|-----|
| Post correction entry | Exception row action | Paired journal per composer schema |
| Custom journal | Exception row action | Multi-leg manual entry |
| Reverse transaction | Exception or txn view | Undo incorrect posting |
| Reclassify | Exception action | Move amount contribution ↔ loan repayment |
| Master account manual CR/DR | Master account → transactions | Rare corrections (document reason) |

**Always:** Log reason in exception notes; verify master pool after correction.

**Prefer shared mirror helpers** in `AccountingService` over one-off legs:

- `creditMemberCashWithMasterMirror` / `debitMemberCashWithMasterMirror`
- `creditMemberFundWithMasterMirror` / `debitMemberFundWithMasterMirror`

---

## 11. Artisan command runbook

Run from application server with tenant context:

```bash
php artisan <command> --tenants=<tenant-slug>
```

### 11.1 Reconciliation & balances

| Command | Options | When to use |
|---------|---------|-------------|
| `fund:assert-master-invariants` | — | Quick pool check |
| `fund:reconcile --realtime` | `--no-store` | Point-in-time audit without saving |
| `fund:reconcile --daily` | — | Store daily snapshot |
| `fund:reconcile --monthly` | — | Month-end audit |
| `fund:nightly-reconciliation` | — | Full exception sweep |
| `accounting:rebuild-balances` | `--dry-run` | Stored balance ≠ txn lines |
| `bank:auto-match` | — | Batch match attempt |

**Rebuild balances** recalculates `accounts.balance` from sum of transaction lines — use after migration repair or deleted txn bugs. **Always dry-run first.**

### 11.2 Legacy migration repair

| Command | Options | When to use |
|---------|---------|-------------|
| `legacy:repair-excess-loan-repayments` | `--loan=`, `--member=` | Repayments exceed fund-portion target |
| `legacy:repair-misclassified-contributions` | `--member=`, `--delinquent`, `--legacy-routed` | Contributions should be loan repayments |
| `legacy:repair-classified-payments` | `--classified=` | Fix classified CSV before re-import |
| `legacy:rebuild-loan-installments` | `--loan=` | Schedule count/status wrong |
| `legacy:sync-loan-schedules` | `--loan=` | Apply repayments to installments |
| `legacy:reimport-loan-repayments` | `--payments=`, `--dry-run` | Re-run repayment import |
| `legacy:migrate` | full options | Full migration (coordinate with admin) |

**Fund-portion rule:** Legacy loan repayments should stop when `master_portion` (+ settlement) is satisfied — not at the old 50%+16% formula. Excess repair moves overflow back to contributions.

### 11.3 Statements

```bash
php artisan statements:generate --tenants=<slug> --period=YYYY-MM [--notify] [--member=]
```

---

## 12. Legacy migration repair

**UI:** Audit & System → Migration

### 12.1 Typical post-migration issues

| Symptom | Likely cause | Repair path |
|---------|--------------|-------------|
| Member cash too high | Misclassified loan repayments as contributions; phantom cash from bad reversal | `legacy:repair-misclassified-contributions`; `accounting:rebuild-balances` |
| Loan repayments > master portion | Old formula target (99k vs 79.5k) | `legacy:repair-excess-loan-repayments --loan=` |
| Installments wrong count | Schedule rebuild needed | `legacy:rebuild-loan-installments` |
| Pool drift after import | Missing mirrors or deleted txns | Rebuild balances + nightly recon |

### 12.2 Safe repair order

```
1. Repair classification data (CSV or misclassified commands)
2. Repair loan repayment totals (excess command)
3. Sync loan schedules / rebuild installments
4. accounting:rebuild-balances --dry-run → then live
5. fund:nightly-reconciliation
6. fund:assert-master-invariants
```

### 12.3 Verification queries (conceptual)

For loan *L* after repair:

- Σ `loan_repayments.amount` ≈ `loan.fullRepaymentThreshold()` (`master_portion` + settlement)
- `loan.repaid_to_master` ≤ `loan.master_portion`
- Member cash/fund pass `MemberInvariantService`

---

## 13. Year-end close gates

**Audit & System → Year-end close**

Readiness report checks (among others):

- Master pool mirrors
- Member drifts
- Open fund postings / cash-outs
- Open or critical reconciliation exceptions
- Uncleared bank lines
- Contribution cycle completeness
- Loan portfolio consistency

**Do not close** with open critical exceptions unless policy explicitly allows write-off.

See [fiscal-year-end-close.md](fiscal-year-end-close.md).

---

## 14. Reports and snapshots

| Output | Where |
|--------|-------|
| Reconciliation PDF | Reports → reconciliation export; or snapshot row download |
| Collections CSV/XLSX | Reports |
| Loan portfolio | Reports |
| Audit log export | Reports |
| Snapshot history | Reconciliation → Snapshots tab |

Snapshots include: ledger mismatch count, unposted bank rows, open exception count, coverage matrix by flow.

---

## 15. When batch posting is halted

**Cause:** `MASTER_IMBALANCE_UNRESOLVED` or manual halt in settings.

**Effect:** Halt-sensitive jobs blocked (contribution apply, EMI apply, late fees, bank auto-match, etc.) — see `ScheduledJobRegistry` `halt_sensitive: true` entries.

**Recovery:**

1. Fix underlying master/member drift.
2. Resolve or write off critical exceptions.
3. Run `fund:nightly-reconciliation` — clears gate on success.
4. Or admin clears halt in settings after verification.

**Jobs UI** shows halt reason when present.

---

## 16. Symptom → entry point quick table

| Symptom | Start here |
|---------|------------|
| Reconciliation badge | Finance → Reconciliation → Queue |
| Bank queue badge | Finance → Bank clearing |
| Member says balance wrong | Member → Transactions + `accounting:rebuild-balances --dry-run` |
| Master ≠ sum of members | `fund:assert-master-invariants` |
| Deposit accepted but not in bank | `CASH_DEPOSIT_UNBANKED` / bank queue `operations` |
| Double contribution | `DUPLICATE_CONTRIBUTION_DEBIT` |
| Loan shows wrong repayment total | Loan → Imported/legacy repayments; `legacy:repair-excess-loan-repayments` |
| Post-migration pool drift | §12 repair order |
| Year-end blocked | Audit & System → Year-end close readiness |
| Need audit trail for auditor | Reconciliation → Snapshots + Reports |

---

## Appendix A — Standard journal patterns

### Contribution collection

| DR | CR |
|----|-----|
| Member cash | |
| Master cash (mirror) | |
| | Member fund |
| | Master fund (mirror) |

### Loan disbursement

| DR | CR |
|----|-----|
| Member fund (portions) | |
| Master fund (mirror) | |
| Loan account | |
| | Member cash |
| | Master cash (mirror) |

### Cash-out approval

| DR | CR |
|----|-----|
| Member cash | |
| Master cash (mirror) | |

Plus uncleared `bank_transactions` row.

### Loan repayment

1. Cash-in mirror (CR member + master cash)  
2. Cash debit (DR member + master cash)  
3. Fund credit (CR member + master fund)  
4. Loan principal credit (CR loan account)

---

## Appendix B — Related documentation

| Document | Topic |
|----------|-------|
| [fund-flow-dynamics.md](fund-flow-dynamics.md) | Full money-flow diagrams |
| [collection_cycle_workflow.md](collection_cycle_workflow.md) | Collection phases |
| [loan-repayment-operations.md](loan-repayment-operations.md) | EMI and repayment |
| [production-runbook.md](production-runbook.md) | Server operations |
| [.cursor/rules/accounting-master-member-sync.mdc](../.cursor/rules/accounting-master-member-sync.mdc) | Mirror rules for developers |

---

## Appendix C — Tests as behavioural spec

When in doubt about expected behaviour, consult:

| Test file | Covers |
|-----------|--------|
| `tests/Feature/Tenant/ReconciliationAndMigrationClosureTest.php` | Exception actions, pool drift |
| `tests/Feature/Tenant/BankClearingQueueWorkflowTest.php` | Clearing workflow |
| `tests/Feature/Tenant/LegacyMigrationTest.php` | Legacy repair scenarios |
| `tests/Feature/Tenant/FiscalClosePhaseThreeTest.php` | Year-end gates |
| `tests/Feature/Tenant/ComplianceLayerTest.php` | Invariant compliance |
