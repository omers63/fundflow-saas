# FundFlow — Reconciliation & Scheduler
**Administrator one-pager** · Print or use as a 5-slide deck

Full reference: [fund-flow-reconciliation-and-scheduler.md](fund-flow-reconciliation-and-scheduler.md)

---

### Slide 1 — What runs automatically

FundFlow runs scheduled commands **every day per tenant** (your fund's database). You do not need to start these manually unless troubleshooting.

| Time | What happens | Admin action usually needed? |
|------|--------------|------------------------------|
| 06:00–06:30 | Pool checks + reconciliation sweep | Only if exceptions appear |
| 07:00–07:30 | Loan defaults, late fees, delinquency digest | Review digest if sent |
| 08:00 | Bank auto-match | Review unmatched lines in Bank clearing |

**Jobs UI:** Audit & System → Jobs — re-run any command; history shows success/failure.

---

### Slide 2 — Three things that look similar

| Name | Where to see it | What it means |
|------|-----------------|---------------|
| **Reconciliation queue** | Finance → Reconciliation | **Fix these** — live problems |
| **Daily/monthly snapshot** | Reconciliation → Snapshots | Audit history for accountants |
| **Bank clearing queue** | Finance → Bank clearing | Uncleared deposits/cash-outs + unmatched imports |

Operations (accept deposit, apply contribution) post to the ledger **immediately**. Reconciliation **checks** the books later — it does not replace your accept/match actions.

---

### Slide 3 — Monthly collection calendar

| Day | System does |
|-----|-------------|
| **1st** | Opens contribution cycle; sends due notifications |
| **5th** | Batch-applies contributions (debits cash, credits fund) |
| **6th** | Batch EMI repayments; closes collection windows (overdue) |
| **3rd** | Generates member statements |

**Important:** Members can still be collected **before** Day 5 when cash arrives (deposit accept, bank import post-to-member) — the scheduler is a backstop, not the only collection path.

---

### Slide 4 — Batch posting halt (when automation stops)

If master pool is critically broken, the system **halts** these jobs:

- Contribution init / apply / close window
- EMI apply / close window
- Late fees
- Bank auto-match

**You can still:** accept deposits, approve cash-outs, match bank lines manually, post corrections.

**Recovery:** Fix reconciliation exceptions (especially `MASTER_IMBALANCE_UNRESOLVED`) → re-run **Nightly reconciliation** from Jobs, or ask accountant to clear after verification.

Jobs page shows a **halt badge** with reason when active.

---

### Slide 5 — Daily admin checklist (reconciliation-aware)

| Task | Where | Tied to scheduler |
|------|-------|-------------------|
| Review reconciliation badge | Finance → Reconciliation | After 06:30 nightly batch |
| Clear bank queue | Finance → Bank clearing | After 08:00 auto-match |
| Accept deposits / cash-outs | Fund Postings / Cash-out | Posts ledger → realtime checks |
| Import bank CSV | Bank clearing | Feeds auto-match + manual match |
| Run job manually | Audit & System → Jobs | Same as cron; respects halt gate |

**Do not** combine deposit Path A (fund posting accept) and Path B (bank import post-to-member) for the same money — double-count risk.

**Manual:** [manual-administrator.md](manual-administrator.md) · **Money flows:** [fund-flow-one-pager-admins.md](fund-flow-one-pager-admins.md)
