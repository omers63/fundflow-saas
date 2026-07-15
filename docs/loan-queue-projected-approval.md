# Loan queue: projected approval and disbursement

This document explains how the **Projected approval** column on the **Intake** tab and the **Projected disbursement** column on the **Process queue** tab are calculated.

Both labels use the same engine: `App\Services\Loans\LoanQueueProjectionService`. The wording differs by stage (approval vs disbursement), but the math is identical.

This is a **planning estimate**, not a scheduled date or a guarantee.

---

## The basic question

> How long until this loan’s fund tier has enough money to cover **this loan**, after everyone **ahead of it** in the queue?

The answer is expressed as **Ready now** or an approximate number of **months**.

---

## Step 1 — Can it be funded right now?

For each loan, the service resolves its **fund tier** (assigned tier for approved/partial loans; expected tier for pending intake).

It then computes a **shortfall**:

```
shortfall = (demand ahead of this loan + this loan’s need) − tier disbursable pool
```

| Input | Meaning |
|-------|---------|
| **Tier disbursable pool** | Per-tier lending headroom available today (`FundTier::disbursable_pool`) |
| **Demand ahead** | Sum of amounts for loans in front of this one in the same tier queue |
| **This loan’s need** | Requested amount (intake / pending) or **remaining to disburse** (process queue) |

**Outcomes:**

- **Shortfall ≤ 0** → **Ready now** (green badge)
- **Shortfall > 0** → continue to Step 2 for a month estimate

---

## Step 2 — Queue order (who counts as “ahead”)

Queue position matters, especially on **Intake**.

### Approved / partially disbursed (process queue and tier cards)

Loans in the same fund tier are ordered by `queue_position`, then `applied_at`. Everything above this loan in that list counts toward **demand ahead**.

### Pending (intake)

Two layers are counted:

1. All **approved** and **partially disbursed** loans already in that tier
2. Other **pending** applications in the same expected tier, ordered **emergencies first**, then **FIFO** by `applied_at`

A new application behind a large backlog gets a longer projection even if the pool is not empty today.

---

## Step 3 — How fast is money expected to arrive?

The service uses **two** monthly inflow estimates for the **master fund**, then scales each by the tier’s **percentage** allocation.

### Forward-looking estimate (primary)

```
expected monthly inflow =
    open-period contribution collection targets
  + average monthly EMI repayments due in the next 3 months
```

- Contribution targets come from the current open collection period (`ContributionCycleService`).
- EMI component is the sum of pending/overdue installments on active loans due in the next 3 months, divided by 3.

### Historical estimate (sanity band)

```
historical monthly inflow =
    max(0, (master fund credits − debits) over last 3 months) / 3
```

Net growth of the master fund ledger over the trailing 3 months, averaged per month.

### Tier share

Each estimate is multiplied by `tier.percentage / 100`. For example, a tier at 100% receives the full inflow; a tier at 50% receives half.

---

## Step 4 — Shortfall → months

For each monthly inflow estimate (if ≥ 0.01):

```
months = ceil(shortfall ÷ tier monthly inflow)
```

The displayed value is a **range** when the two estimates differ:

| Display | When |
|---------|------|
| **Ready now** | Shortfall ≤ 0 |
| **~N month(s)** | Both estimates yield the same month count |
| **N–M months** | Estimates differ |
| **N+ months** | Upper bound exceeds the display cap |
| **> 6 months** | Minimum estimate is beyond `MAX_MONTHS_DISPLAY` (6) |
| **No projected inflow** | Neither estimate produces a usable monthly inflow |

The UI caps long ranges for readability (see `LoanQueueProjectionService::MAX_MONTHS_DISPLAY`).

---

## Worked example

Assume:

- Tier can pay out **SAR 50,000** today
- Loans ahead need **SAR 120,000**
- This loan needs **SAR 80,000**

```
shortfall = (120,000 + 80,000) − 50,000 = SAR 150,000
```

If forward inflow for this tier is **SAR 50,000/month** → ~**3 months**  
If historical inflow is **SAR 30,000/month** → ~**5 months**

Badge: **~3–5 months**

---

## What this estimate does **not** include

- Manual admin decisions (approve faster, reject, emergency triage after the snapshot)
- Exact collection calendar dates (uses monthly averages)
- Bank clearance timing
- The **shared master-fund ceiling** when multiple tiers overlap at 100% — that constraint is reflected separately in the process queue **Coverage** column and the **Ready to process** KPI

---

## Where it appears in the product

| Location | Column label |
|----------|----------------|
| Loan queue → Intake | Projected approval |
| Loan queue → Process queue | Projected disbursement |
| Loan queue → Tier queue cards | Projection per queued row |
| Member loan cards / hub | Same label via `MemberLoansHubService` |

---

## Configuration

Tenant admins can tune the projection engine under **Settings → Fund tiers → Queue projection**. Settings are stored in the `loan_queue_projection` settings group (`App\Support\LoanQueueProjectionSettings`).

| Setting | Default | Effect |
|---------|---------|--------|
| **Approved / partial loans ahead** | Within fund tier only | Whether queued demand in front counts only within the loan’s tier or across all tiers (global tier order). |
| **Pending applications ahead** | Within expected fund tier only | On intake, whether other pending applications ahead are tier-scoped or fund-wide (emergencies first, then FIFO). |
| **Use forward-looking estimate** | On | Primary inflow band from expected collections + EMIs. |
| **Include open-period contribution targets** | On | Adds current collection period expected contributions. |
| **Include outstanding contribution arrears** | Off | Adds unpaid contribution / late-fee arrears to forward inflow. |
| **EMI forecast window (months)** | 3 | Installments due in the next N months are averaged to one monthly EMI figure. |
| **Use historical estimate** | On | Secondary band from net master-fund ledger growth. |
| **Historical lookback (months)** | 3 | Trailing window for the historical band. |
| **Apply fund tier allocation %** | On | When off, full master-fund inflow is used for every tier (overlapping 100% tiers). |
| **Maximum months on badge** | 6 | Caps long labels (`> N months`, `N+ months`). |

---

## Implementation reference

| Piece | Location |
|-------|----------|
| Projection engine | `app/Services/Loans/LoanQueueProjectionService.php` |
| Queue datasets | `app/Services/Loans/LoanQueueService.php` |
| Table columns | `app/Filament/Support/LoanQueueTable.php` |
| Tests | `tests/Feature/Tenant/LoanQueueProjectionServiceTest.php` |

---

## Related concepts

| Term | Meaning |
|------|---------|
| **Queued demand** (KPI) | Total remaining to disburse across tier queues |
| **Ready to process** (KPI) | Count of loans fundable **right now** (coverage > 0) |
| **Coverage** (process column) | Full / partial tranche / waiting on pool for this disbursement step |
| **Tier headroom** | Per-tier policy cap; not additive when tier percentages overlap |
