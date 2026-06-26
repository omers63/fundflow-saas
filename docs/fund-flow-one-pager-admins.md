# FundFlow — Admin Operations Guide
**Administrator one-pager** · Print or use as a 6-slide deck

---

### Slide 1 — Account layers

| Layer | Purpose |
|-------|---------|
| **Master Bank** | Imported statement lines (source of truth for the real bank) |
| **Master Cash** | Pool cash on hand; must equal sum of all member cash |
| **Master Fund** | Total pool value; must equal sum of all member fund |
| **Member Cash / Fund** | Per-member balances |
| **Loan sub-account** | Principal tracking per loan |

**Rule:** Every member cash/fund movement has a matching master leg in the same transaction.

---

### Slide 2 — Deposits (Path A: Fund Posting)

| Step | Action | Result |
|------|--------|--------|
| 1 | Member submits deposit | Pending fund posting |
| 2 | **Accept** posting | CR master cash + CR member cash |
| 3 | — | Uncleared bank line created |
| 4 | Import CSV when available | Statement line appears |
| 5 | **Clear / Match** | Links ledger to bank; no extra journal |

Auto-collection may run after accept if contributions or EMIs are due.

---

### Slide 3 — Bank import (Path B: Statement first)

| Step | Action | Status |
|------|--------|--------|
| 1 | Import CSV | `imported` |
| 2 | **Mirror to cash** | `mirrored` — master bank + master cash |
| 3 | **Post to member** | `posted` — member cash credited |
| 4 | Auto-collection | Contributions / EMIs if due |

Do **not** combine Path A and Path B for the same deposit (double-count risk).

---

### Slide 4 — Contributions & collection

**Collection journal (each cycle):**

- DR member cash + DR master cash (mirror)
- CR member fund + CR master fund (mirror)

| Situation | System behaviour |
|-----------|------------------|
| Full cash available | Full collection on cycle open or deposit |
| Partial cash | Partial collection; remainder PENDING/OVERDUE |
| Late | Late fee tiers after grace window |

---

### Slide 5 — Loans & cash-out

**Disbursement (ledger only):**

- DR member fund + master fund (mirror) — member + master portions
- DR loan account (principal)
- CR member cash + master cash (mirror)
- **No bank payout at disbursement**

**Cash-out (bank payout):**

- Member requests → admin **accepts**
- DR member cash + master cash (mirror)
- Uncleared bank line → **Clear/Match** when transfer appears on statement

**Repayment:** Cash-in mirror → cash debit → fund credit → loan principal credit.

---

### Slide 6 — Admin checklist

| Task | Where | Watch for |
|------|-------|-----------|
| Accept deposits | Fund Postings | Match uncleared lines after import |
| Import bank CSV | Bank Accounts → Statement lines | Duplicates blocked by hash |
| Mirror / Post | Statement lines | Only `imported` / `mirrored` rows |
| Approve cash-outs | Cash-out requests | Member cash ≥ amount |
| Loan queue | Loans | Guarantor required; overrides logged |
| Reconciliation | Nightly batch | MASTER_*_DRIFT, MEMBER_*_DRIFT exceptions |
| Legacy migration | Legacy Migration | Run `accounting:rebuild-balances` if cash drift suspected |
| Fund-only loans | Loans | 100% member fund + zero settlement → no EMI schedule |

---

*Full technical reference: `docs/fund-flow-dynamics.md` · Reconciliation & scheduler: `docs/fund-flow-reconciliation-and-scheduler.md` · Implementation: `docs/fund-flow-implementation.md`*
