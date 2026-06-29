# Loan repayment operations

Where to post or apply loan repayments for one member, and how to run repayment collection for all members in the tenant admin panel and scheduled jobs.

For the data model (EMI installments vs repayment events vs legacy `loan_repayments` rows), see [emi-installments-vs-repayments.md](./emi-installments-vs-repayments.md).

## One member (admin)

### Primary action: Apply open-period repayment

**Cycle windows:** Each period runs from **cycle start day** (default **6th**) through the **day before** the next cycle start (e.g. **February** = 6 Feb – 5 Mar inclusive). An EMI due **5 Mar** belongs to the **February** cycle when the business date is in February.

Use this on any **active** loan that has an unpaid EMI in the **open period window**:

| Location | Path |
|----------|------|
| Loans list | **Fund Management → Loans → Loans** (Portfolio tab) — row actions (⋯ menu) |
| Loan queue | **Fund Management → Loans → Loan queue** — same row actions when the loan is active |
| Loan detail | Open the loan (**View** / edit) — header actions include the same workflow actions |

This action debits the member’s **cash** for the **current open period** EMI (plus late fee if applicable), via `LoanRepaymentService::applyOpenPeriodRepaymentForMember()`.

### Related actions (same menus)

| Action | Purpose |
|--------|---------|
| **Early settle** | Pay all remaining installments from member cash |
| **Partial early settle** | Pay a lump sum and roll up or skip future installments |

### Repayment history (read-only)

| Location | Shows |
|----------|--------|
| **Members → [member] → Repayments** | Paid installments across loans |
| **Loans → [loan] → Repayment schedule** | Installment list for that loan (no “post payment” action on this tab) |

---

## All members (EMI collection workspace)

**Fund Management → Loans → Loans** — **EMI collection** tab (first tab; **EMI collected** for posted installments). Default tab when opening the list is **Loans** (portfolio).

Mirrors **Contributions → To collect**:

| Tab | Purpose |
|-----|---------|
| **EMI collection** | Members with pending EMIs through the open period (and arrears). Row **Apply now** or bulk **Apply now** debits cash via `LoanInstallmentCollectionService`. |
| **EMI collected** | Installments already paid (due through the open period). |

**Eligibility reviews** is the last tab on the same Loans list (no separate cluster item).

---

## All members (scheduled batch)

There is still **no** requirement to use the UI for month-end batch runs — the scheduled job below remains available.

Batch repayment is done via **scheduled commands**, runnable from the admin UI or CLI.

### Admin UI

**System → Jobs & commands** — run **`loans:apply-repayments`** (“Apply loan repayments”).

- Applies the scheduled EMI for **all active loans** for the open period.
- Optional period override on CLI: `--month=` and `--year=`.

### Production schedule

From `routes/console.php`:

| Command | Schedule | Role |
|---------|----------|------|
| `loans:apply-repayments` | Monthly, **6th**, 06:00 | Batch EMI collection from member cash for the period |
| `loans:close-emi-window` | Monthly, **6th**, 00:45 | Marks unpaid installments **overdue** (does not debit cash) |
| `contributions:apply` | Monthly, **5th**, 09:00 | Posts contributions for all members; collection cycle may then settle loan EMIs from cash |

### CLI

```bash
php artisan loans:apply-repayments
php artisan loans:apply-repayments --month=6 --year=2026
```

Batch posting is blocked when reconciliation has halted batch operations (see **Jobs & commands** banner and **Reconciliation queue**).

---

## Automatic collection (incremental)

EMIs are also collected when **member cash increases**, without a separate “Run EMI collection” button.

`LoanInstallmentCollectionService` collects installments in **open collection** states for the **open period and arrears** (not future installments). This is triggered after events such as:

- Accepted **deposits**
- **Contributions → To collect → Apply now** (single or bulk)
- Manual **cash credits** on member accounts

### Setting: auto-allocate after contribution

**Settings → Loans → “Auto-allocate posted contributions to loan”**

When enabled, after a contribution is posted, the app attempts open-period loan repayment from member cash when possible (`LoanRepaymentService` / collection cycle).

---

## Member self-service

**Member portal → My loans** — **Pay open-period repayment**

Same economic effect as admin **Apply open-period repayment** for that member’s active loan.

---

## Quick reference

| Goal | Where |
|------|--------|
| Post one member’s current EMI | **Loans** or **Loan queue** → **Apply open-period repayment** |
| Pay off a loan early | Same places → **Early settle** (or partial early settle) |
| Collect pending EMIs for all members (UI) | **Loans → EMI collection** tab (bulk or per member) |
| Run EMI for all active loans (scheduled period) | **System → Jobs & commands** → **`loans:apply-repayments`** |
| Collect after cash is credited | Often automatic; or **EMI collection** / **Contributions → To collect** (+ optional auto-allocate setting) |
| View paid installments | **Members → Repayments** or **Loans → Repayment schedule** |

**Note:** **Contributions** screens are for **membership contributions**, not a dedicated “collect all loan repayments” workspace. For all-member EMI posting, use **Jobs & commands** or `loans:apply-repayments`.

---

## Implementation map

| Layer | Class / command | Responsibility |
|-------|-----------------|----------------|
| Open-period (manual) | `LoanRepaymentService::applyOpenPeriodRepaymentForMember()` | One member, current open period installment |
| Period batch | `LoanRepaymentService::applyRepayments()` / `loans:apply-repayments` | All active loans for a given month/year |
| Cash-driven EMI | `LoanInstallmentCollectionService` | Incremental collection when cash balance increases |
| Contribution cycle | `ContributionCollectionCycleService` | Household allocation; calls loan collection after contribution settlement |
| Filament actions | `LoanFilamentActions::applyOpenRepayment()` | Admin UI wiring on loans list, queue, and view |
| Member UI | `MemberLoanFilamentActions::payOpenPeriodRepayment()` | Member portal |

See also: [loan-delinquency-workflow.md](./loan-delinquency-workflow.md), [loan-ux-and-workflow-recommendations.md](./loan-ux-and-workflow-recommendations.md).
