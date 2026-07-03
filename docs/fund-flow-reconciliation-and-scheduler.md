# FundFlow — Reconciliation & Scheduler

This document explains how **operational posting**, **reconciliation**, the **scheduler**, and **system jobs** fit together. It complements the money-movement detail in [fund-flow-dynamics.md](fund-flow-dynamics.md).

**One-page summaries:** [accountants](fund-flow-one-pager-reconciliation-scheduler-accountants.md) · [admins](fund-flow-one-pager-reconciliation-scheduler-admins.md)

**Related manuals:** [manual-accountant.md](manual-accountant.md) · [manual-administrator.md](manual-administrator.md)

---

## Table of contents

1. [Big picture — three layers](#1-big-picture--three-layers)
2. [Realtime path on every ledger post](#2-realtime-path-on-every-ledger-post)
3. [Daily scheduler timeline](#3-daily-scheduler-timeline)
4. [Monthly collection cycle](#4-monthly-collection-cycle)
5. [Nightly reconciliation batch](#5-nightly-reconciliation-batch)
6. [Audit snapshots vs exception queue](#6-audit-snapshots-vs-exception-queue)
7. [Batch posting gate](#7-batch-posting-gate)
8. [Operational flows through the lifecycle](#8-operational-flows-through-the-lifecycle)
9. [System jobs UI](#9-system-jobs-ui)
10. [Async queue jobs (not on cron)](#10-async-queue-jobs-not-on-cron)
11. [Summary](#11-summary)

---

## 1. Big picture — three layers

```mermaid
flowchart TB
    subgraph ops["Operational layer (admin / member actions)"]
        DEP["Deposit accept"]
        CONTRIB["Contribution post / apply"]
        LOAN["Loan disburse / repay / EMI"]
        CASHOUT["Cash-out approve"]
        BANK["Bank CSV import + match"]
        LEG["Legacy migration import"]
    end

    subgraph ledger["Ledger layer"]
        ACCT["AccountingService<br/>member + master mirrors"]
        TXN["Transaction rows"]
        OBS["TransactionObserver"]
    end

    subgraph recon["Reconciliation layer"]
        RT["Realtime checks<br/>(on each Transaction)"]
        SNAP["fund:reconcile<br/>audit snapshots"]
        NIGHT["fund:nightly-reconciliation<br/>exceptions + auto-resolve"]
        GATE["BatchPostingGate<br/>halt batch jobs if critical"]
        EXC["ReconciliationException queue"]
    end

    subgraph sched["Scheduler (routes/console.php)"]
        CRON["Laravel schedule:run<br/>per tenant DB"]
        JOBS["System → Jobs UI<br/>manual re-run"]
    end

    ops --> ACCT --> TXN --> OBS --> RT
    RT --> EXC
    NIGHT --> EXC
    NIGHT --> GATE
    CRON --> SNAP
    CRON --> NIGHT
    CRON --> BATCH["Batch commands<br/>contributions / loans / bank"]
    BATCH --> ACCT
    GATE -.->|blocks| BATCH
    JOBS --> CRON
    LEG --> ACCT
```

**Key idea:** Operations **post intent** to the ledger immediately. Reconciliation **verifies** that intent (realtime + nightly). The scheduler **drives recurring collection and checks** on a calendar. They are separate steps — bank clearance, for example, does not re-post cash when you match a line.

| Layer | What it does | When it runs |
|-------|--------------|--------------|
| **Operational** | Admin/member actions (accept deposit, apply contribution, etc.) | On demand |
| **Ledger** | Mirrored journal entries via `AccountingService` | Same transaction as the action |
| **Realtime recon** | Lightweight guards on each `Transaction` | Immediately after post |
| **Snapshots** | Historical audit report stored in `reconciliation_snapshots` | Daily 06:20, monthly 2nd |
| **Nightly batch** | Full domain sweeps, auto-resolve, halt gate | Daily 06:30 |
| **Scheduler** | Recurring collection, fees, bank match, statements | Calendar in `routes/console.php` |

---

## 2. Realtime path on every ledger post

```mermaid
sequenceDiagram
    participant Op as Operational service
    participant Acct as AccountingService
    participant Txn as Transaction
    participant Obs as TransactionObserver
    participant Recon as ReconciliationService
    participant Coll as ContributionCollectionCycleService
    participant EMI as LoanInstallmentCollectionService

    Op->>Acct: credit/debit with mirrors
    Acct->>Txn: create transaction line(s)
    Txn->>Obs: created event
    Obs->>Recon: onTransactionPosted()

    Recon->>Recon: audit log TRANSACTION_POSTED
    Recon->>Recon: journal balanced? (UNBALANCED_ENTRY)
    Recon->>Recon: late-fee exemption rules
    Recon->>Recon: member cash/fund drift (MEMBER_*_DRIFT)
    Recon->>Recon: master pool drift (MASTER_*_POOL_DRIFT)

    Note over Acct,EMI: Skipped during master pool mirroring<br/>AccountingService::masterPoolMirrorInProgress()

    Acct->>Coll: onMemberCashIncreased (when cash credited)
    Coll->>EMI: try contribution + EMI from available cash
```

**Realtime checks** (`ReconciliationService::onTransactionPosted()`):

| Check | Exception code | Severity |
|-------|----------------|----------|
| Journal DR = CR for reference | `UNBALANCED_ENTRY` | critical |
| Late-fee exemption consistency | varies | varies |
| Member component formula vs stored balance | `MEMBER_CASH_DRIFT`, `MEMBER_FUND_DRIFT` | high |
| Master cash/fund vs Σ members | `MASTER_CASH_POOL_DRIFT`, `MASTER_FUND_POOL_DRIFT` | high |

**Skipped when:**

- `ReconciliationService::realtimeChecksSuspended()` is active
- `AccountingService::masterPoolMirrorInProgress()` — paired master/member legs post in one logical operation

Heavy domain sweeps (contributions past window, bank uncleared pipeline, EMI state) run in the **nightly batch**, not on every transaction.

---

## 3. Daily scheduler timeline

All scheduled tenant commands use `TenantAwareScheduledCommand` — `schedule:run` executes each command **once per tenant database**.

Source: `routes/console.php` · Registry: `App\Support\ScheduledJobRegistry`

| Time | Command | Role | Halt-sensitive |
|------|---------|------|----------------|
| **06:00** | `fund:assert-master-invariants` | Quick gate: master cash/fund = Σ members | No |
| **06:20** | `fund:reconcile --daily` | Audit snapshot for yesterday | No |
| **06:30** | `fund:nightly-reconciliation` | Control layer: exceptions, auto-resolve, halt gate | No |
| **07:00** | `loans:check-defaults` | Delinquency / guarantor / auto-transfer | No |
| **07:15** | `contributions:apply-late-fees` | Tiered contribution + EMI late fees | **Yes** |
| **07:30** | `delinquency:send-digest` | Admin digest notification | No |
| **08:00** | `bank:auto-match` | Match imported bank lines ↔ uncleared postings | **Yes** |
| *every minute* | `announcements:dispatch-scheduled` | Scheduled member announcements | No |

```mermaid
gantt
    title Typical daily reconciliation and ops window
    dateFormat HH:mm
    axisFormat %H:%M

    section Pool checks
    assert-master-invariants     :06:00, 10m
    reconcile daily snapshot     :06:20, 10m
    nightly-reconciliation       :06:30, 30m

    section Collections and risk
    loan defaults check          :07:00, 15m
    apply late fees              :07:15, 15m
    delinquency digest           :07:30, 10m
    bank auto-match              :08:00, 20m
```

**Manual run:** Audit & System → Jobs — same commands, subject to `BatchPostingGate` for halt-sensitive entries.

---

## 4. Monthly collection cycle

| Day | Time | Command | Role | Halt-sensitive |
|-----|------|---------|------|----------------|
| **1st** | 08:00 | `contributions:init-cycle` | Create pending contribution rows | **Yes** |
| **1st** | 08:00 | `loans:send-due-notifications` | EMI due notifications | No |
| **1st** | 09:00 | `contributions:notify` | Contribution due notifications | No |
| **2nd** | 06:30 | `fund:reconcile --monthly` | Previous month audit snapshot | No |
| **3rd** | 08:00 | `statements:generate --notify` | Monthly statements + notify | No |
| **5th** | 09:00 | `contributions:apply` | Batch debit cash → credit fund | **Yes** |
| **6th** | 00:30 | `contributions:close-window` | Unpaid → overdue | **Yes** |
| **6th** | 00:45 | `loans:close-emi-window` | Unpaid installments → overdue | **Yes** |
| **6th** | 06:00 | `loans:apply-repayments` | Batch EMI collection from cash | **Yes** |

```mermaid
flowchart LR
    subgraph d1["Day 1"]
        INIT["contributions:init-cycle<br/>pending rows"]
        NOTIFY["contributions:notify"]
        LOAN_N["loans:send-due-notifications"]
    end

    subgraph d5["Day 5"]
        APPLY["contributions:apply<br/>DR cash → CR fund"]
    end

    subgraph d6["Day 6"]
        EMI["loans:apply-repayments<br/>batch EMI from cash"]
        CLOSE_C["contributions:close-window<br/>→ OVERDUE"]
        CLOSE_E["loans:close-emi-window"]
    end

    subgraph daily["Daily (throughout month)"]
        FEES["contributions:apply-late-fees"]
        RT_COLL["Realtime: onMemberCashIncreased<br/>when deposits / imports credit cash"]
    end

    INIT --> NOTIFY
    NOTIFY --> APPLY
    APPLY --> CLOSE_C
    CLOSE_C --> FEES
    LOAN_N --> EMI
    EMI --> CLOSE_E
    RT_COLL -.->|can collect early| APPLY
```

**Between scheduled runs:** any cash credit (deposit accept, bank import post-to-member, loan disbursement cash leg) triggers `ContributionCollectionCycleService::onMemberCashIncreased()` and `LoanInstallmentCollectionService` — members are not limited to Day 5/6 batch only.

---

## 5. Nightly reconciliation batch

`fund:nightly-reconciliation` → `ReconciliationService::runNightlyBatch()`:

```mermaid
flowchart TD
    START["NIGHTLY_RECON_START"] --> CLEAR["Clear exception queue<br/>(fresh sweep)"]
    CLEAR --> PRE["Master balanced?<br/>tiny drift → rounding suspense"]
    PRE -->|critical fail| HALT["BatchPostingGate HALT<br/>stop halt-sensitive jobs"]
    PRE -->|ok| DOMAIN

    subgraph DOMAIN["Domain sweeps"]
        C["reconcileContributions"]
        L["reconcileLoansAndEmi"]
        F["reconcileFundTiers"]
        B["reconcileBankClearing"]
        LF["reconcileLateFees"]
        M["reconcileMemberInvariants"]
    end

    DOMAIN --> AUTO["attemptAutoResolve<br/>open exceptions"]
    AUTO --> POST["Re-assert master balanced"]
    POST --> DONE["NIGHTLY_RECON_COMPLETE"]

    HALT --> EXC["ReconciliationException<br/>Finance → Reconciliation"]
    DOMAIN --> EXC
```

| Sweep | Typical exceptions raised |
|-------|---------------------------|
| Master pre-check | `MASTER_IMBALANCE_UNRESOLVED` (critical → halt) |
| Contributions | `PENDING_PAST_WINDOW_CLOSE`, `COLLECTED_WITHOUT_POST`, tier mismatches |
| Loans / EMI | `EMI_COLLECTED_LEDGER_MISSING`, `ACTIVE_BEFORE_FULL_DISBURSE`, schedule state |
| Fund tiers | Tier / fund allocation consistency |
| Bank clearing | `RECON_UNMATCHED_BANK_LINE`, `UNMATCHED_CASH_ENTRY`, `STALE_PENDING` |
| Late fees | `FEE_WRONG_TIER`, `FEE_INCOME_DRIFT` |
| Member invariants | `MEMBER_CASH_DRIFT`, `MEMBER_FUND_DRIFT` |

After domain sweeps, **auto-resolve** runs only for **safe metadata fixes** (defer stale bank lines, reset collection status flags, set EMI overdue clocks, clear grace-period flags). **Ledger-posting corrections** (rounding adjustments, missing mirror legs, EMI reposts, fee tier fixes, etc.) require explicit admin action via **Retry auto-resolve** or manual correction on the exception row. A **post-batch** master balance check may halt again if drift remains critical.

---

## 6. Audit snapshots vs exception queue

These are **different products** — do not confuse them.

| Mechanism | UI action | Command | Output | Use |
|-----------|-----------|---------|--------|-----|
| **Real-time snapshot** | **Run check now** (simple mode) or **Real-time snapshot** (advanced) | `fund:reconcile --realtime` | Saved `reconciliation_snapshots` row (`mode=realtime`) | Ad-hoc “what does the book look like right now?” |
| **Daily snapshot** | **Daily snapshot** (advanced) | `fund:reconcile --daily` | Saved snapshot (`mode=daily`) | Routine daily audit record |
| **Monthly snapshot** | **Monthly snapshot** (advanced) | `fund:reconcile --monthly` | Saved snapshot (`mode=monthly`) | Month-end / period-close evidence |
| **Exception queue re-check** | **Exception queue re-check** (advanced) | `fund:nightly-reconciliation` | Live `reconciliation_exceptions` rows (queue replaced) | Refresh actionable remediation list |

**Scheduled runs:** daily snapshot at **06:20**, exception queue + monthly snapshot at **06:30** (monthly on the **2nd**).

### 6.1 What each run covers

#### Shared idea

- **Snapshots** (real-time, daily, monthly) all call `ReconciliationReportService::buildReport()` with the **same ledger audit checks as of run time**.
- **Exception queue re-check** calls `ReconciliationService::runNightlyBatch()` — a **different engine** that raises operational exceptions for admin workflow.

```mermaid
flowchart LR
    subgraph snapshots["Audit snapshots (all three modes)"]
        R["ReconciliationReportService::buildReport()"]
        R --> STORE["reconciliation_snapshots table"]
        R --> READ["Reads open exception count<br/>(does not refresh queue)"]
    end

    subgraph queue["Exception queue re-check"]
        N["ReconciliationService::runNightlyBatch()"]
        N --> EXC["reconciliation_exceptions table<br/>(cleared and rebuilt)"]
        N --> GATE["BatchPostingGate<br/>(may halt batch jobs)"]
    end
```

#### Real-time snapshot (`mode=realtime`)

| Aspect | Detail |
|--------|--------|
| **When** | On demand (**Run check now**) or `fund:reconcile --realtime` |
| **Time window** | **As of now** — no period filter |
| **Period metrics** | None (`ledger_lines_in_period` and `bank_mirrored_in_period` are null) |
| **Stores history?** | Yes (unless CLI `--no-store`) |
| **Refreshes exception queue?** | **No** — only reports how many exceptions were open at snapshot time |
| **Can halt batch jobs?** | No |

**Use when:** investigating today, after imports/corrections, or before approving a manual journal.

#### Daily snapshot (`mode=daily`)

| Aspect | Detail |
|--------|--------|
| **When** | On demand or scheduled **06:20** |
| **Time window** | Ledger checks **as of now**, plus **yesterday’s calendar day** (app timezone) for period metrics |
| **Period metrics** | Count of ledger lines with `transacted_at` yesterday; count of bank lines mirrored/posted yesterday |
| **Stores history?** | Yes |
| **Refreshes exception queue?** | No |
| **Can halt batch jobs?** | No |

**Use when:** keeping a daily audit trail and day-level activity counts.

#### Monthly snapshot (`mode=monthly`)

| Aspect | Detail |
|--------|--------|
| **When** | On demand or scheduled **2nd at 06:30** |
| **Time window** | Ledger checks **as of now**, plus **previous calendar month** for period metrics |
| **Period metrics** | Same two counts as daily, but for the full prior month |
| **Stores history?** | Yes |
| **Refreshes exception queue?** | No |
| **Can halt batch jobs?** | No |

**Use when:** month-end packs, external audit, or comparing month-level posting volume.

#### Exception queue re-check (`fund:nightly-reconciliation`)

| Aspect | Detail |
|--------|--------|
| **When** | On demand or scheduled **06:30** |
| **Time window** | As of now |
| **Stores audit snapshot?** | **No** |
| **Refreshes exception queue?** | **Yes** — deletes all existing exceptions, re-runs sweeps, raises fresh rows |
| **Auto-fixes without admin?** | **Metadata only** (defer stale bank lines, reset collection status, EMI overdue clocks, grace flags). **No silent ledger posting.** |
| **Can halt batch jobs?** | **Yes** — on critical `MASTER_IMBALANCE_UNRESOLVED` |

**Use when:** you need an up-to-date **Exceptions** tab after formula fixes, data repairs, or before relying on the queue for remediation.

### 6.2 Snapshot audit checks (all three snapshot modes)

Every snapshot mode runs these **ledger integrity** checks (severity per check: ok / warning / critical / skipped):

| Check key | What it verifies |
|-----------|------------------|
| `ledger_balances` | Stored account balance = net of that account’s transactions |
| `global_trial` | Σ credits vs Σ debits across all ledger lines |
| `paired_control_totals` | Master cash/fund pool vs Σ member mirrors (tolerance-aware) |
| `bank_statement_vs_book` | Optional: `master_cash` vs declared statement balance (skipped if not configured) |
| `contributions_ledger` | Contribution rows have ledger lines; master fund credits match contribution totals |
| `member_portal_posting_integrity` | Accepted fund postings credited member + master cash correctly |
| `bank_transaction_posting_integrity` | Imported bank lines posted to ledger with expected legs |
| `sms_transaction_posting_integrity` | Skipped in SaaS (SMS import not used) |
| `contribution_flow_integrity` | Contribution cycle paired fund/cash legs |
| `membership_application_fee_integrity` | Enrollment fee → cash + master fees |
| `subscription_fee_integrity` | Annual subscription fee → master fees |
| `active_loans_schedule_vs_ledger` | Active loans: loan account outstanding vs expected (schedule − partial paid) |
| `approved_loans_disbursement_vs_ledger` | Approved partial disbursements vs loan ledger |
| `loan_disbursement_cash_payout_integrity` | Disbursement cash payout vs approved loan |
| `loan_installment_flow_integrity` | Per-installment repayment legs (skipped for legacy-import loans; validated at loan level instead) |
| `member_cash_transfer_integrity` | Dependent transfer debit/credit pairing |
| `orphan_loan_accounts` | Loan-type accounts without a loan row |
| `bank_pipeline` | Unposted bank imports and uncleared bank lines (warning if backlog) |

Each snapshot also includes:

- **Pipeline summary** — unposted / uncleared bank row counts and amounts
- **Control layer summary** — open exception count **at snapshot time** (by domain/code)
- **Coverage matrix** — which flows map to which checks
- **Verdict** — pass if no critical issues; warning count = number of checks with `severity=warning`

**Only difference between real-time / daily / monthly:** the `mode` tag, optional **period metrics** window (none / yesterday / previous month), and scheduling.

### 6.3 Exception queue domains (re-check only)

`runNightlyBatch()` sweeps raise **workflow exceptions** not fully represented as snapshot checks:

| Domain sweep | Typical exception codes |
|--------------|-------------------------|
| Master pre/post check | `MASTER_IMBALANCE_UNRESOLVED`, `MASTER_CASH_POOL_DRIFT`, `MASTER_FUND_POOL_DRIFT` |
| Contributions | `PENDING_PAST_WINDOW_CLOSE`, `COLLECTED_WITHOUT_POST`, `CONTRIBUTION_MISSING_MASTER_CREDIT`, `CONTRIBUTION_MEMBER_FUND_MISSING`, `CONTRIBUTION_AMOUNT_MISMATCH`, `DUPLICATE_CONTRIBUTION_DEBIT`, `ORPHAN_MASTER_FUND_CREDIT`, `CONTRIBUTION_EXEMPT_COLLECTED` |
| Loans / EMI | `EMI_COLLECTED_LEDGER_MISSING`, `EMI_OVER_COLLECTION`, `EMI_MISSED_SUFFICIENT_CASH`, `ACTIVE_BEFORE_FULL_DISBURSE`, `SCHEDULE_BEFORE_FULL_DISBURSE`, `GRACE_CYCLE_EMI_DEBIT`, `EMI_OVERDUE_WITHOUT_CLOCK`, `GUARANTOR_BORROWER_DUPLICATE_DEBIT`, `DISBURSEMENT_MEMBER_CASH_MISSING` |
| Fund tiers | `FUND_TIER_OVER_COMMITTED` |
| Bank clearing | `RECON_UNMATCHED_BANK_LINE`, `RECON_AMBIGUOUS_MATCH`, `UNMATCHED_CASH_ENTRY`, `STALE_PENDING`, `CASH_DEPOSIT_UNBANKED`, `AMOUNT_MISMATCH` |
| Late fees | `FEE_WRONG_TIER`, `FEE_INCOME_DRIFT`, `FEE_POSTED_WRONG_ACCOUNT`, `REPLACEMENT_PRIOR_TIER_NOT_REVERSED`, `RECON_AUTO_FEE_EXEMPTION_REVERSAL` |
| Member invariants | `MEMBER_CASH_DRIFT`, `MEMBER_FUND_DRIFT` |

Admin actions on the **Exceptions** tab (resolve, escalate, write-off, post correction, **Retry auto-resolve** for ledger fixes) apply to this queue only.

### 6.4 Quick decision guide

| Your goal | Run this |
|-----------|----------|
| “Is the ledger structurally sound right now?” | **Real-time snapshot** / **Run check now** |
| “Save today’s audit card for the file” | **Daily snapshot** (or wait for 06:20) |
| “Month-end evidence + monthly activity counts” | **Monthly snapshot** (or wait for 2nd 06:30) |
| “Refresh the Exceptions tab after fixes” | **Exception queue re-check** |
| “Fix a specific open exception” | Exceptions tab → row actions (not a snapshot run) |

**Common mistake:** running **Run check now** and expecting stale exceptions to disappear. Snapshots **read** the queue count; only **Exception queue re-check** rebuilds it.

### 6.5 UI locations

| Action | Simple mode | Advanced mode |
|--------|-------------|---------------|
| Real-time snapshot | **Run check now** (header) | **Real-time snapshot** |
| Exception queue re-check | — | **More reconciliation runs** → **Exception queue re-check** |
| Daily snapshot | — | **More reconciliation runs** → **Daily snapshot** |
| Monthly snapshot | — | **More reconciliation runs** → **Monthly snapshot** |

All four require the tenant user **admin** flag.

---

## 7. Batch posting gate

```mermaid
flowchart LR
    NIGHT["nightly-reconciliation<br/>MASTER_IMBALANCE critical"] --> GATE["BatchPostingGate<br/>halt = true"]
    GATE --> BLOCK["Blocks halt-sensitive jobs"]

    BLOCK --> J1["contributions:init-cycle"]
    BLOCK --> J2["contributions:apply"]
    BLOCK --> J3["contributions:close-window"]
    BLOCK --> J4["loans:close-emi-window"]
    BLOCK --> J5["loans:apply-repayments"]
    BLOCK --> J6["bank:auto-match"]
    BLOCK --> J7["contributions:apply-late-fees"]

    ADMIN["Resolve drift + recon clears"] --> GATE2["gate cleared"]
    GATE2 --> OK["Batch jobs allowed again"]
```

**Triggers halt:**

- `MASTER_IMBALANCE_UNRESOLVED` critical exception (open or raised during nightly batch)
- Manual halt via system settings (`batch_posting_halted`)

**Enforcement:**

- `ScheduledJobRegistry` — `halt_sensitive: true` on affected jobs
- `SystemJobRunnerService` — blocks manual runs from Jobs UI
- `EnsuresBatchPostingAllowed` trait — blocks Artisan command execution

**Recovery:**

1. Fix underlying master/member pool drift.
2. Resolve or write off critical exceptions.
3. Re-run `fund:nightly-reconciliation` (clears gate on success), or clear halt in settings after verification.

**Not blocked:** deposit accept, manual corrections, snapshot commands, `fund:assert-master-invariants`, notifications, delinquency checks.

---

## 8. Operational flows through the lifecycle

```mermaid
flowchart TB
    subgraph inflow["Cash in"]
        D1["Deposit accept"] --> MC["CR master cash + CR member cash"]
        D2["Bank import"] --> MB["Master bank line"]
        MB --> MIRROR["Mirror → master cash"]
        MIRROR --> MC
        MC --> UNC["Uncleared BankTransaction"]
        UNC --> MATCH["Admin match / bank:auto-match"]
    end

    subgraph use["Cash used"]
        MC --> CON["Contribution collection<br/>DR cash + DR master cash mirror"]
        CON --> MF["CR member fund + CR master fund mirror"]
        MC --> EMI2["EMI / loan repayment<br/>DR cash → fund + loan principal"]
    end

    subgraph outflow["Cash out"]
        MC --> CO["Cash-out approve<br/>DR member + master cash"]
        CO --> UNC2["Uncleared bank payout line"]
    end

    subgraph loan["Loan fund leg"]
        MF --> DISB["Disbursement<br/>DR member+master fund mirror"]
        DISB --> MC2["CR member+master cash mirror"]
    end

    MC --> OBS2["Every leg → Transaction → realtime recon"]
    MF --> OBS2
```

Every arrow that creates a `Transaction` feeds the realtime reconciliation path (section 2). Bank **match/clear** updates linkage — it does **not** post additional cash legs when done correctly.

---

## 9. System jobs UI

**Location:** Audit & System → Jobs

| Feature | Behaviour |
|---------|-----------|
| Job list | Mirrors `ScheduledJobRegistry::all()` |
| Manual run | `SystemJobRunnerService::run()` — records `SystemJobRun` |
| Halt badge | Shown when `BatchPostingGate::isHalted()` |
| History | Per-run exit code, duration, output snippet |

Scheduled cron and manual runs use the **same Artisan commands** — only the trigger differs (`SystemJobRun::TRIGGER_SCHEDULED` vs `TRIGGER_MANUAL`).

---

## 10. Async queue jobs (not on cron)

| Job | Trigger | Purpose |
|-----|---------|---------|
| `RunLegacyMigrationPaymentsJob` | Legacy Migration UI | Classify + import historical payments |
| `ClassifyLegacyPaymentsJob` | Legacy Migration UI | Payment classification only |
| Tenant provisioning jobs | Central app | Cache dirs, tenant setup |

Legacy migration runs **outside** the nightly scheduler. After import, operators may run repair commands (`legacy:repair-excess-loan-repayments`, `accounting:rebuild-balances`, etc.) before relying on reconciliation results.

---

## 11. Summary

FundFlow separates **posting** (what happened economically), **clearance** (does the bank agree?), and **reconciliation** (do the books still obey pool rules?).

1. Day-to-day actions post mirrored ledger entries immediately.
2. Each `Transaction` triggers lightweight realtime checks.
3. Every morning the scheduler runs pool assertions, stores an audit snapshot, then runs a full exception sweep that can halt batch collection jobs if the master pool is broken.
4. Monthly commands open and close contribution/EMI windows; between those dates, cash credits still drive automatic collection via `onMemberCashIncreased`.
5. Admins can re-run any scheduled job from **System → Jobs**, subject to the batch halt gate.

---

## References

| Resource | Path |
|----------|------|
| Scheduler definition | `routes/console.php` |
| Job registry | `app/Support/ScheduledJobRegistry.php` |
| Nightly batch | `app/Services/ReconciliationService.php` |
| Halt gate | `app/Support/BatchPostingGate.php` |
| Manual job runner | `app/Services/SystemJobRunnerService.php` |
| Mirror rules | `.cursor/rules/accounting-master-member-sync.mdc` |
| Accountant manual | `docs/manual-accountant.md` |
| Administrator manual | `docs/manual-administrator.md` |
