# Delinquency workspace — UX notes

Product notes for the standalone **Operations → Delinquency** workspace, and how it relates to Contributions Arrears, Members Arrears, and other admin surfaces.

---

## 1. Operations → Delinquency

### Role

Delinquency is the **risk / enforcement** workspace (not cycle-scoped):

| Panel                     | What it lists / does                                                     |
| ------------------------- | ------------------------------------------------------------------------ |
| **Overview**        | Insights KPIs, last maintenance run, next-step cards                     |
| **Overdue**         | EMI rows with`status = overdue` on **active** loans              |
| **Guarantor**       | Active loans with a guarantor past grace**or** already transferred |
| **Policy breaches** | Members failing consecutive/rolling missed-cycle policy                  |
| **Related**         | Deep-links to Contributions Arrears, Members Arrears, Settings           |

Header **Delinquency tools**: Run delinquency check, Mark overdue only, Send admin digest, Export guarantor exposure. **Policy → Sync policy breaches** re-evaluates active members.

Loan-level **Loan Transfer** is on **Overdue** rows. On **Guarantor**, the **Delinquency transfer** group holds **Guarantor Transfer** and restore borrower liability.

### Navigation

- Sidebar: **Operations**, after **Disbursements** (`TenantNavigation::SORT_DELINQUENCY`)
- Slug: `/admin/delinquency?sideTab=…`
- Legacy Loans URLs (`?tab=delinquency`, `overdue_installments`, `guarantor_exposure`) redirect here via `LoanResource` / `DelinquencyTabRegistry`

### Is it cycle-based?

**No.** Delinquency queries ignore contribution cycle. Cycle close is an *input* to marking installments overdue, not a filter on these queues.

### How “Overdue” is determined

A row appears only when:

1. `loan_installments.status = 'overdue'`
2. The loan’s `status = 'active'`

**Past due alone is not enough.** Something must flip `pending` → `overdue` after the contribution-cycle deadline (header tools, `loans:check-defaults`, EMI window close).

Until that runs, **Loans → Collection → Arrears** can still show unpaid EMIs while **Delinquency → Overdue** stays empty.

### How “Guarantor” is determined

Active loan + guarantor + (liability transferred **or** late≥grace with overdue installments).

### Why the queues can look empty

Empty Delinquency ≠ “no one owes.” Often installments were never escalated to `overdue`. Use **Run delinquency check** after a cycle deadline.

### UX fit vs Collection / Members Arrears

| Surface                                      | Job                                                           |
| -------------------------------------------- | ------------------------------------------------------------- |
| **Loans → Collection → Arrears**     | Collect unpaid EMIs for a**selected period**            |
| **Delinquency**                        | After mark-overdue: risk, guarantor workflow, policy breaches |
| **Members → Arrears**                 | Person rollup (contributions + EMIs)                          |
| **Contributions → Ledger → Arrears** | Unpaid contribution periods                                   |

### Implementation pointers

- Page: `app/Filament/Tenant/Pages/DelinquencyWorkspacePage.php`
- Tables: `app/Filament/Support/LoanDelinquencyTables.php`
- Tools: `app/Filament/Support/LoanDelinquencyHeaderActions.php`
- URLs: `app/Filament/Tenant/Support/DelinquencyTabRegistry.php`
- Service: `app/Services/Loans/LoanDelinquencyService.php`
- Broader workflow: `docs/loan-delinquency-workflow.md`

---

## 2. Contributions / Members (stay on source pages)

Contribution delinquency inventory stays **Contributions → Ledger → Arrears** (Apply / Clear tooling). Members **Arrears** stays the person rollup. Delinquency **Related** only deep-links those queues.

Loan EMI tools (mark overdue, full check, digest) belong **only** on Delinquency — not on Contributions header actions.

---

## 3. Cross-surface links (dashboard, jobs, settings, reports, …)

After extracting Delinquency from Loans, most other admin surfaces needed **copy / URL clarity**, not new tooling:

| Surface                                      | Status      | Notes                                                                                                     |
| -------------------------------------------- | ----------- | --------------------------------------------------------------------------------------------------------- |
| **Dashboard**                          | Linked      | Overdue KPIs / quick links use`DelinquencyTabRegistry::url('overdue')`                                  |
| **Loan / contribution insights**       | Linked      | Overdue + guarantor KPIs open the workspace panels                                                        |
| **Reports**                            | Linked      | Guarantor exposure card opens**Delinquency → Guarantor**                                           |
| **Digest / notifications / templates** | Linked      | `delinquency_digest` action URLs open the workspace; Communications template catalog unchanged          |
| **Settings → Collection**             | Config only | Policy + digest/check schedule; helper text points to**Operations → Delinquency** for review       |
| **Jobs / scheduled job registry**      | Config only | `loans:check-defaults` and `delinquency:send-digest` descriptions mention reviewing under Delinquency |
| **Audit & System**                     | No change   | No delinquency-specific deep-links                                                                        |

**Do not** re-host Settings forms, Apply/Clear contribution arrears, or member inventory modals on the Delinquency page — deep-link from **Related** instead.

---

## 4. “Run delinquency check” toast

> Overdue: *N* · Arrears: *N* · Clear: *N* · Warnings: *N* · Guarantor debits: *N*

| Result                     | Meaning                                         | Where to check                 |
| -------------------------- | ----------------------------------------------- | ------------------------------ |
| **Overdue**          | EMIs newly flipped to`overdue`                | Delinquency → Overdue         |
| **Arrears**          | Policy-breach count (not Contributions Arrears) | Delinquency → Policy breaches |
| **Clear**            | Active members clear of that policy this run    | —                             |
| **Warnings**         | Borrower warning notifications within grace     | Notification logs              |
| **Guarantor debits** | Guarantor fund debited past grace               | Delinquency → Guarantor       |

Overview also shows the last run from this workspace when available.
