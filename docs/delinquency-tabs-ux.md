# Delinquency tabs — Loans vs Contributions (UX notes)

Product notes on where delinquency lives, how the Loans Delinquency tab works, and why Contributions does not use the same primary-tab name.

---

## 1. Loans → Delinquency tab

### Role

On the Loans page, Delinquency sits beside **Collection** (period operations) and **Portfolio** (lifecycle). It is the **risk / enforcement** workspace:

| View | What it lists |
|------|----------------|
| **Overdue installments** | EMI rows with `status = overdue` on **active** loans |
| **Guarantor exposure** | Active loans with a guarantor that are past grace **or** already transferred |

Loan-level actions belong here (mark overdue, transfer/restore guarantor liability).

### Is it cycle-based?

**No.** The cycle picker / cycle header only appears on **Collection**. Delinquency queries ignore `selectedCycle`.

Cycle close is an *input* to marking installments overdue, not a filter on the Delinquency lists. Overdue and guarantor risk are **portfolio-wide** signals; scoping them to a single contribution cycle would hide older risk and understate exposure.

(`?cycle=` may still appear in the URL when navigating from Collection; it does not filter Delinquency data.)

### How “Overdue installments” is determined

A row appears only when:

1. `loan_installments.status = 'overdue'`
2. The loan’s `status = 'active'`

**Past due alone is not enough.** Something must flip `pending` → `overdue` after the **contribution-cycle deadline** for that EMI’s due date (via `ContributionCycleService`, not merely the calendar due day):

- Header tools: **Run delinquency check** / **Mark overdue only**
- Scheduled `loans:check-defaults`
- EMI window close (`loans:close-emi-window`)

Until that runs, **Collection → Arrears** can still show members with unpaid EMIs while **Delinquency → Overdue** stays empty.

### How “Guarantor exposure” is determined

A loan appears when it is **active**, has a **guarantor**, and either:

- liability already transferred (`guarantor_liability_transferred_at` set), **or**
- `late_repayment_count >=` grace cycles (setting `default_grace_cycles`, default **2**) **and** at least one installment is still `overdue`

So you typically need marked overdues **and** a history of late repayments (or an explicit transfer)—not just “someone owes this month.”

### Why the tab can look empty

Most common causes:

- Delinquency check / EMI close has never run → installments still `pending` past the deadline
- No guarantors on loans, or grace threshold not reached
- Loans not in `active` status

Empty Delinquency ≠ “no one owes.” It often means “not yet escalated to overdue status.” Use **Delinquency tools → Run delinquency check** (or Mark overdue only) after a cycle deadline if you expect rows.

### UX fit vs Collection / Members Arrears

| Surface | Job |
|---------|-----|
| **Loans → Collection → Arrears** | Collect unpaid EMIs for a **selected period** (includes still-`pending`) |
| **Loans → Delinquency** | After mark-overdue: risk, late fees, guarantor workflow |
| **Members → Arrears** | Person rollup (contributions + EMIs) |

Delinquency belongs on Loans and should **not** be cycle-based. Name overlap with Collection “Arrears” is intentional but easy to confuse: the populations differ on purpose.

### Implementation pointers

- Tables: `app/Filament/Support/LoanDelinquencyTables.php`
- Marking / maintenance: `app/Services/Loans/LoanDelinquencyService.php`
- Page wiring: `app/Filament/Tenant/Resources/Loans/Pages/ListLoans.php`, `LoanResource.php`
- Broader workflow: `docs/loan-delinquency-workflow.md`

---

## 2. Should Contributions have a Delinquency tab?

### Short answer

**Yes, contributions need a delinquency surface — and they already have one.** It is named **Arrears**, not **Delinquency**.

### Where contribution delinquency lives today

| Concern | Where it lives |
|---------|----------------|
| Unpaid contribution periods (cross-cycle inventory) | **Contributions → Ledger → Arrears** |
| Unpaid for a selected period (collect) | **Contributions → Contributions tab → Arrears** segment |
| Policy breach / administrative hold | **Members** (Inactive hold) + Settings → Delinquency policy |
| Loan overdue status + guarantor | **Loans → Delinquency** |

### Why Loans gets a tab named “Delinquency” and Contributions does not

Loan delinquency needs machinery contributions do not:

- Flip EMI `pending` → `overdue` after cycle deadline
- Late-repayment counts / grace
- Guarantor transfer / restore

Contribution “delinquency” is simpler: **missing posted periods after the deadline** (plus late fees when posting). There is no guarantor path and no separate installment status machine, so the inventory lives under **Contributions → Arrears**.

### Should we add a Contributions primary “Delinquency” tab?

**Usually no.** A new primary tab would duplicate **Ledger → Arrears** and blur:

- **Cycle Arrears** — period collection ops  
- **Ledger Arrears** — cross-cycle risk inventory (the real contribution equivalent of Loans → Delinquency)

Better UX alignment (if desired later):

- Treat **Ledger → Arrears** as the contribution counterpart to Loans → Delinquency (optional rename of the pill for parity)
- Keep cycle **Arrears** for collection only

### Bottom line

Contributions already have a delinquency surface (**Arrears**). Loans need a separate **Delinquency** tab because of overdue status escalation and guarantor workflows that do not apply to contributions the same way.

Loan EMI tools (**Mark overdue**, full delinquency check, admin digest) belong only on **Loans → Delinquency** — not on Contributions header actions.

### Loan eligibility — late settlement thresholds

Two independent Settings sections block new loan applications after too many **late-settled** cycles (posted/paid with `is_late`):

| Settings section | Tab | Counts |
|------------------|-----|--------|
| **Late contribution thresholds** | Contributions | Late posted contributions |
| **Late EMI thresholds** (formerly “Missed EMI thresholds”) | Loans | Late paid EMIs |

Outstanding unpaid contribution/EMI arrears (excluding the open cycle) still block loans separately, before late-history checks.

**Delinquency policy** (consecutive / rolling **missed unpaid** closed cycles) remains a flagging signal for daily arrears checks; it does not replace the late-settlement loan gate.
