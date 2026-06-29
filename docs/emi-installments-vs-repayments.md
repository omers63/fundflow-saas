# EMI installments vs repayments

Conceptual guide to how FundFlow models loan schedules, payment events, and legacy import rows. For operational steps (where to click, which commands to run), see [loan-repayment-operations.md](./loan-repayment-operations.md).

## Summary

| Concept | Table / model | Role |
|---------|----------------|------|
| **EMI installment** | `loan_installments` (`LoanInstallment`) | The schedule: what is due, when, and whether it is paid |
| **Repayment (operation)** | *(no dedicated row)* | The act of settling an installment: debit cash, post fund leg, mark installment `paid` |
| **Repayment (import record)** | `loan_repayments` (`LoanRepayment`) | Legacy / CSV import history; synced onto installments for migrated loans |

**Mnemonic:** An installment is an invoice line; a repayment is payment against that line. For balances, delinquency, EMI collection, and reconciliation, **`loan_installments` is the source of truth**.

---

## EMI installment = scheduled obligation

A **`LoanInstallment`** is one row on a loan’s amortization schedule.

- **EMI** (equated monthly installment) is the fixed amount due each collection cycle.
- Typical fields: `installment_number`, `due_date`, `amount`, `status` (`pending` → `overdue` → `paid`), `late_fee_amount`, `late_fee_tier`, `collection_status`, `overdue_since`, `paid_at`, `paid_by_guarantor`.
- Installments are created when the loan is disbursed or when the schedule is built/regenerated.
- Collection cycles align EMIs to fund periods (e.g. an EMI due **5 Mar** belongs to the **February** cycle when the cycle runs 6 Feb – 5 Mar). See [loan-repayment-operations.md](./loan-repayment-operations.md).

```
Loan
 ├── Installment 1  (pending)
 ├── Installment 2  (overdue)
 └── Installment 3  (paid)
```

---

## Repayment = paying an installment

In product language and in most services, **“repayment” means settling an installment**, not a separate schedule entity.

A live repayment:

1. Debits **member cash** (installment principal + late fee when applicable), with **master cash** mirror.
2. Credits **member fund** / **master fund** for the principal leg (via ledger posting when the installment becomes `paid`).
3. Updates the **`LoanInstallment`** to `status = paid` and sets `paid_at`, `is_late`, etc.

Ledger posting runs when an installment transitions to `paid`:

- **Observer:** `App\Observers\LoanInstallmentObserver`
- **Ledger:** `App\Services\Loans\LoanLedgerService::postLoanRepayment()`

So: **one installment → at most one live repayment event**. The installment is the bucket; repayment fills it.

### Paths that apply repayments (same target, different triggers)

| Path | Service / command | What it does |
|------|-------------------|--------------|
| Admin **Apply open-period repayment** | `LoanRepaymentService::applyOpenPeriodRepaymentForMember()` | Pays the EMI for the current open collection cycle |
| Monthly batch | `loans:apply-repayments` → `LoanRepaymentService::applyRepayments()` | Same for all active loans in a given month/year |
| **EMI collection** tab; cash increases | `LoanInstallmentCollectionService` | Collects open-period and arrears EMIs when member cash is available |
| After contribution posting | `ContributionCollectionCycleService` | May auto-allocate cash to loan EMIs (see Settings → auto-allocate) |
| **Early settle** / partial early settle | `LoanEarlySettlementService` | Pays multiple remaining installments in one workflow |

All live paths **update `loan_installments`** and post ledger entries tied to the installment (morph reference on transactions). They do **not** create rows in `loan_repayments`.

---

## `LoanRepayment` = legacy / import history

The **`loan_repayments`** table (`LoanRepayment` model) is a **separate, historical log**, not the live schedule.

| Aspect | Detail |
|--------|--------|
| **Created by** | Legacy CSV migration (`LegacyPaymentImportService`), repayment CSV import (`LoanRepaymentImportService`) |
| **Columns** | `loan_id`, `amount`, `paid_at`, `notes` (plus timestamps) |
| **Not created by** | Normal admin/member repayment, scheduled `loans:apply-repayments`, or cash-driven EMI collection |
| **Sync to schedule** | `LegacyImportedLoanScheduleSyncService` maps imported rows onto `loan_installments` (marks paid without re-posting ledger when import already posted) |
| **Admin UI** | Loan detail → **Imported/legacy repayments** tab — only visible when `loan_repayments` rows exist for that loan |

There is **no foreign key** from `loan_repayments` to `loan_installments`. Sync matches payments to schedule rows by member, payment date, amount, and active repayment window (overpayments can spill to the next loan window).

### Where to view paid history in the UI

| Screen | Data source |
|--------|-------------|
| **Members → [member] → Repayments** | Paid **`LoanInstallment`** rows (`paidLoanInstallments` relationship) |
| **Loans → [loan] → Repayment schedule** | Full installment list for that loan |
| **Loans → [loan] → Imported/legacy repayments** | **`LoanRepayment`** import rows only (legacy) |

---

## Data model relationships

```
Loan
 ├── hasMany LoanInstallment     ← schedule (source of truth)
 └── hasMany LoanRepayment       ← import / legacy payment log only

LoanInstallment (paid)
 └── triggers LoanLedgerService::postLoanRepayment() via observer
```

---

## Implementation map (quick reference)

| Layer | Class | Responsibility |
|-------|--------|----------------|
| Schedule row | `App\Models\Tenant\LoanInstallment` | Due amount, status, late fees, collection state |
| Pay one EMI (open period) | `LoanRepaymentService` | Manual / batch period repayment |
| Cash-driven collection | `LoanInstallmentCollectionService` | Incremental EMI collection when cash increases |
| Ledger on paid | `LoanLedgerService::postLoanRepayment()` | Fund leg when installment becomes `paid` |
| Import log | `App\Models\Tenant\LoanRepayment` | Historical rows; sync via `LegacyImportedLoanScheduleSyncService` |
| Observer | `LoanInstallmentObserver` | Connects `paid` status change to ledger |

---

## Related docs

- [loan-repayment-operations.md](./loan-repayment-operations.md) — admin UI, EMI collection tab, scheduled jobs
- [loan-delinquency-workflow.md](./loan-delinquency-workflow.md) — overdue installments, defaults, guarantor debits
- [loan_lifecycle_workflows.md](./loan_lifecycle_workflows.md) — disbursement through payoff
