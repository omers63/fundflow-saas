# FundFlow Administrator — Operations Manual

This manual explains how fund **administrators** operate the tenant admin panel: members, collections, loans, bank clearing, deposits, cash-outs, reconciliation overview, settings, and system jobs.

**Admin URL:** `https://<your-fund-domain>/admin`  
**Sign in:** Tenant admin account (`tenant` guard).

For fund-flow accounting rules, see [fund-flow-one-pager-admins.md](fund-flow-one-pager-admins.md) and [fund-flow-dynamics-admins.md](fund-flow-dynamics-admins.md). For reconciliation and the daily/monthly scheduler, see [fund-flow-reconciliation-and-scheduler.md](fund-flow-reconciliation-and-scheduler.md).

---

## Table of contents

1. [Admin panel layout](#1-admin-panel-layout)
2. [Daily operations checklist](#2-daily-operations-checklist)
3. [Members](#3-members)
4. [Collections (contributions)](#4-collections-contributions)
5. [Loans](#5-loans)
6. [Disbursements](#6-disbursements)
7. [Deposits (fund postings)](#7-deposits-fund-postings)
8. [Cash-out requests](#8-cash-out-requests)
9. [Bank clearing](#9-bank-clearing)
10. [Reconciliation overview](#10-reconciliation-overview)
11. [Reports](#11-reports)
12. [Membership applications and member requests](#12-membership-applications-and-member-requests)
13. [Messages and support](#13-messages-and-support)
14. [Settings](#14-settings)
15. [Audit & System hub](#15-audit--system-hub)
16. [Scheduled jobs](#16-scheduled-jobs)
17. [Legacy migration](#17-legacy-migration)
18. [Operational rules administrators must respect](#18-operational-rules-administrators-must-respect)
19. [Monthly calendar](#19-monthly-calendar)

---

## 1. Admin panel layout

The sidebar is grouped into three areas:

| Group | Items |
|-------|--------|
| **Operations** | Members, Loans (cluster), Collections, Disbursements |
| **Finance** | Bank clearing, SMS clearing*, Reconciliation*, Reports |
| **System** | Audit & System, Settings |

\*Shown when the feature is enabled for the tenant.

**Dashboard** (`/admin`) — KPIs, pool health, collection gauge, loan queue preview, shortcuts into workflows below.

**Top bar:** Current contribution cycle chip, messages inbox (admins), language switch.

Many workflows are **not** on the sidebar but reachable from the dashboard, member workspace, or Audit & System hub (deposits, cash-outs, applications, accounts, jobs).

---

## 2. Daily operations checklist

| Task | Where | Notes |
|------|--------|-------|
| Review pending deposits | Fund postings or dashboard | Accept/reject member deposits |
| Review pending cash-outs | Cash-out requests | Accept triggers cash debit + uncleared bank line |
| Bank work queue | Finance → Bank clearing | Match imports to operational lines |
| Loan queue | Loans → Loan queue | Approve/reject applications |
| Reconciliation badge | Finance → Reconciliation | Open exceptions if any |
| Messages | Top bar inbox | Member communications |

**Morning (automated):** The system runs scheduled jobs per tenant (invariants, reconciliation batch, late fees, bank auto-match). If **batch posting is halted**, halt-sensitive jobs are blocked until critical reconciliation issues are resolved.

---

## 3. Members

**Menu:** Operations → **Members** → `/admin/members`

### List tabs

Filter by status: active, inactive, migration_pending, delinquent, suspended, withdrawn, terminated.

### Member workspace (view member)

From a member record, use tabs and relation managers:

| Area | Use for |
|------|---------|
| **Loans** | Member’s loan history |
| **Contributions** | Collection history and arrears |
| **Transactions** | Cash / fund / loan ledger |
| **Accounts** | Account balances |
| **Repayments** | Imported/legacy repayment rows |
| **Dependents** | Household structure |
| **Guarantor exposure** | Loans guaranteed |
| **Messages** | Direct messaging |

### Creating and editing members

- **Create member** — manual onboarding or post-migration.
- **Edit** — status changes follow [member-status-spec.md](member-status-spec.md); do not use ad-hoc status dropdowns where policy applies freeze/unfreeze flows.

### Member requests

**URL:** `/admin/member-requests` — freeze, unfreeze, withdrawal, and other formal requests.

---

## 4. Collections (contributions)

**Menu:** Operations → **Collections** → `/admin/contributions`

### Tabs

| Tab | Purpose |
|-----|---------|
| Contributions | Full ledger of posted contributions |
| To collect | Open cycle — members still owing |
| Collected | Posted this period |
| Arrears | Overdue / partial |

### Collection mechanics

When a contribution is collected:

1. **DR** member cash + **DR** master cash (mirror)
2. **CR** member fund + **CR** master fund (mirror)

Collection may happen:

- On **cycle apply** job (monthly schedule)
- When **member applies** from portal
- When **cash increases** (deposit accept, import) via auto-collection

See [collection_cycle_workflow.md](collection_cycle_workflow.md).

### Manual contribution entry

Use **Create** on Collections for manual adjustments (follow fund policy; may affect reconciliation).

### Late fees

Applied by scheduled job `contributions:apply-late-fees` (daily) after grace window. Tier rules are in Settings → Collection.

---

## 5. Loans

**Menu:** Operations → **Loans** (cluster)

### Loan queue

**Loans → Loan queue** — pending applications and approvals.

Actions: approve, reject, adjust amount, set funding split, grace cycles.

### Loan list tabs

EMI to collect, EMI collected, portfolio, overdue installments, guarantor exposure, eligibility reviews.

### Loan detail

| Relation manager | Purpose |
|------------------|---------|
| **Installments** | Schedule, mark paid, overdue |
| **Disbursements** | Tranche history |
| **Repayments** | Imported/legacy manual repayment rows |
| **Early settle** | Header action on active loans |

### Loan lifecycle (admin view)

```
Application → Approval → Disbursement (ledger) → Active/repaying → Completed
```

**Disbursement posts ledger only** — it does not pay the bank. Member receives **cash credit**; bank payout is separate (cash-out or external process).

**Repayment:** Cash-in mirror → cash debit → fund credit → loan principal credit.

See [loan-repayment-operations.md](loan-repayment-operations.md) and [loan-delinquency-workflow.md](loan-delinquency-workflow.md).

---

## 6. Disbursements

**Menu:** Operations → **Disbursements** → `/admin/disbursements`

Post **approved loan tranches** to the ledger:

- DR member fund + master fund (portions)
- DR loan account (principal)
- CR member cash + master cash (mirror)

Tabs: pending, partial, complete.

---

## 7. Deposits (fund postings)

**URL:** `/admin/fund-postings` (dashboard / member links)

| Status | Admin action |
|--------|--------------|
| Pending | **Accept** or reject |
| Accepted | CR master cash + CR member cash; **uncleared bank line** created |

**Accept** may trigger auto-collection for open contributions or EMIs.

**Do not** also import the same deposit via bank CSV without matching — risk of double-counting. Choose **Path A** (posting first) or **Path B** (bank import first) per [fund-flow-one-pager-admins.md](fund-flow-one-pager-admins.md).

---

## 8. Cash-out requests

**URL:** `/admin/cash-out-requests`

| Step | Result |
|------|--------|
| Member submits | Pending |
| Admin **accepts** | DR member cash + DR master cash; uncleared bank payout line |
| Bank transfer done | **Clear/match** on bank clearing when line appears on statement |
| Admin rejects | No ledger movement; member sees remarks |

---

## 9. Bank clearing

**Menu:** Finance → **Bank clearing** → `/admin/bank-accounts`

### Tabs

| Tab | Purpose |
|-----|---------|
| **Work queue** | Lines needing action (all / from bank file / from operations) |
| **Bank ledger** | Master bank transaction history |
| **Import history** | Past CSV batches and closed lines |

### Typical workflow

```
Import bank CSV → Mirror to cash (if needed) → Post to member (if unallocated)
→ Match operational uncleared lines → Clear
```

### Queue actions

| Action | When to use |
|--------|-------------|
| **Mirror to cash** | Statement line should increase master cash |
| **Match / Auto-match** | Link import line to uncleared deposit or cash-out |
| **Clear without evidence** | Operational clearance per policy |
| **Ignore** | Dismiss non-actionable queue row |

**Rule:** Clearance **links** bank evidence to ledger intent — it should **not** post duplicate cash/fund legs when matched correctly.

Scheduled: `bank:auto-match` daily at 08:00 (per tenant).

---

## 10. Reconciliation overview

**Menu:** Finance → **Reconciliation** → `/admin/reconciliation`

| Tab | Purpose |
|-----|---------|
| Overview | Summary, shortcuts |
| Queue | Open reconciliation **exceptions** |
| History | Resolved exceptions |
| Snapshots | Stored audit reports |
| Methodology | In-product reference |

### Header actions

| Action | Equivalent |
|--------|------------|
| Run now | Realtime snapshot |
| Nightly batch | Full exception sweep |
| Daily / Monthly snapshot | Historical audit store |

**Administrators** review and assign exceptions; **accountants** typically diagnose and post corrections (see [manual-accountant.md](manual-accountant.md)).

If **batch posting is halted**, contribution apply, EMI apply, and similar jobs are blocked until critical master imbalance is resolved.

---

## 11. Reports

**Menu:** Finance → **Reports** → `/admin/reports`

Export collections, loan portfolio, reconciliation PDF, audit log, guarantor exposure (CSV/XLSX/PDF).

Shortcut cards link to live workspaces.

---

## 12. Membership applications and member requests

| Resource | URL | Purpose |
|----------|-----|---------|
| Membership applications | `/admin/membership-applications` | New member onboarding wizard submissions |
| Member requests | `/admin/member-requests` | Freeze, withdraw, etc. |

---

## 13. Messages and support

| Resource | Purpose |
|----------|---------|
| **Messages inbox** (top bar) | Two-way messaging with members |
| **Support requests** | Formal tickets from Help & FAQ |

---

## 14. Settings

**Menu:** System → **Settings** → `/admin/settings`

| Tab | Configures |
|-----|------------|
| General | Fund name, currency, locale |
| Collection | Cycle dates, late fees, grace |
| Loans | Tiers, eligibility, grace cycles, delinquency |
| Fund tiers | Capacity tiers |
| Reconciliation | Tolerances, declared bank balance |
| Guarantor rules | Guarantor policy |
| Fiscal calendar | Business calendar |
| Public page | Marketing / join page |
| Statements | Statement generation rules |
| Communication / Notifications | Channels and templates |
| Bank / SMS templates | Import column mapping |

Changes here affect scheduled jobs and member portal behaviour.

---

## 15. Audit & System hub

**Menu:** System → **Audit & System** → `/admin/audit-system`

| Tab | Purpose |
|-----|---------|
| **Audit log** | Who did what (admin, recon, loans, overrides) |
| **Notification log** | Delivery history |
| **Jobs** | Scheduled commands + manual run |
| **Maintenance** | Backups, purge (admin only) |
| **Migration** | Legacy data import wizard |
| **Year-end close** | Fiscal close readiness and execution |

---

## 16. Scheduled jobs

Accessible from **Audit & System → Jobs** or `/admin/jobs`.

### Daily schedule (per tenant)

| Time | Job | Purpose |
|------|-----|---------|
| 06:00 | Assert master invariants | Pool balance check |
| 06:20 | Daily reconciliation snapshot | Audit store |
| 06:30 | Nightly reconciliation | Exception queue |
| 07:00 | Loan defaults check | Delinquency |
| 07:15 | Apply late fees | Contribution + EMI fees |
| 07:30 | Delinquency digest | Admin email |
| 08:00 | Bank auto-match | Automatic matching |

### Monthly schedule (high level)

| Day | Job |
|-----|-----|
| 1st | Init contribution cycle, loan due notifications |
| 5th | Apply contributions (batch) |
| 6th | Apply loan repayments, close collection/EMI windows |
| 3rd | Generate statements |

**Halt-sensitive jobs** (blocked when reconciliation halts posting): contribution init/apply/close, EMI apply, late fees, bank auto-match.

**Manual run:** Select job → Run. Output and history stored in `SystemJobRun`.

---

## 17. Legacy migration

**Audit & System → Migration** (admin only)

Four-step wizard: members CSV → loans CSV → payments → classify/import.

Runs heavy imports via **queue jobs** (`RunLegacyMigrationPaymentsJob`, `ClassifyLegacyPaymentsJob`).

After migration, coordinate with your accountant for repair commands if drift appears. See [manual-accountant.md](manual-accountant.md) § Legacy migration.

---

## 18. Operational rules administrators must respect

### Master ↔ member mirrors

When posting member cash or fund movements that represent pool activity:

- **Credit member cash** → credit master cash (same transaction)
- **Debit member cash** → debit master cash
- **Credit member fund** → credit master fund first, then member fund
- **Debit member fund** → debit master fund first, then member fund

Do not pass `member_id` on master cash legs unless documented — it can trigger unexpected auto-collection.

### Bank clearance is separate

Ledger posting records **intent**. Matching a bank line confirms evidence — not a second cash posting.

### Loan disbursement vs bank payout

Disbursement credits member cash. Actual bank transfer is a separate cash-out or external process.

### Do not combine Path A and Path B for the same money

See deposits section and fund-flow admin one-pager.

---

## 19. Monthly calendar

Typical month for a standard fund (exact dates in Settings → Collection):

| Phase | Days | What happens |
|-------|------|--------------|
| Cycle open | 1st | Pending contributions created; notices sent |
| Collection window | 1st – 5th | Members pay; batch apply on 5th |
| Window close | 6th | Unpaid → overdue; EMI window closes |
| Late fees | After grace | Daily fee job applies tiers |
| Statements | 3rd (next month) | Monthly statements generated |

Between scheduled dates, **realtime auto-collection** runs when member cash increases.

---

## Quick reference — where to go

| I need to… | Go to… |
|------------|--------|
| Accept a deposit | Fund postings |
| Pay out a member | Cash-out requests → accept → bank clear |
| Import bank CSV | Bank clearing |
| Match bank to deposit | Bank clearing → Work queue |
| Approve a loan | Loans → Loan queue |
| Disburse approved loan | Disbursements |
| See who owes contribution | Collections → To collect |
| Fix a reconciliation flag | Reconciliation → Queue (or escalate to accountant) |
| Change contribution due date | Settings → Collection |
| Run jobs manually | Audit & System → Jobs |
| Import legacy data | Audit & System → Migration |
