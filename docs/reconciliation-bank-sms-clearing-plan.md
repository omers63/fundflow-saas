# Reconciliation + 1:M / M:1 Bank & SMS Clearing — Implementation Plan

**Audience:** Tenant admins / implementers  
**Status:** Planned  
**Related:** [bank-clearing-work-queue-actions.md](bank-clearing-work-queue-actions.md), [bank-clearing-workspace-implementation-plan.md](bank-clearing-workspace-implementation-plan.md)

This plan closes gaps identified after bank clearance **1→N / N→1** group matching was added, and extends the same model to **Bank SMS clearing**, with full reconciliation coverage for both channels.

---

## 1. Current state (baseline)

### Bank clearing (recent work)

| Capability | Status |
|------------|--------|
| 1:1 match / auto-match | Done |
| 1→N (one ops → many bank lines) | Done (`clearMatchGroup`) |
| N→1 (many ops → one bank line) | Done |
| Group unmatch (service layer) | Done |
| Rollback group-aware unmatch | Done |
| Reconciliation `resolveAmbiguousBankMatch` via `clearMatchPair` | Done (1:1 only) |
| True N:M (e.g. 2↔2) | **Not supported** (by design) |
| Admin “Unmatch group” in UI | **Missing** |
| Reconciliation scan for split groups | **1:1 only** |
| Reconciliation resolve group from exception UI | **Missing** |
| Nightly scan hints for combinatorial sums | **Missing** |

**Key files:** `BankClearingMatchService`, `BankTransactionClearanceService`, `BankClearanceMatchGroup`, `BankClearingQueueActions`, `BusinessDayWindowRollbackService`, `ReconciliationCorrectionService`.

### SMS clearing (“Bank SMS clearing”)

| Capability | Status |
|------------|--------|
| CSV import → member match → post to cash | Done |
| Queue: unmatched member / ready to post | Done |
| Duplicate detection | Done |
| Match to bank statement lines | **None** |
| 1:M / M:1 grouping | **None** |
| Reconciliation checks | **Explicitly skipped** (`severity: skipped`, pipeline counts hardcoded `0`) |
| Nightly exceptions | **None** |

**Key files:** `SmsImportService`, `SmsClearingQueueService`, `SmsClearingQueueActions`, `AccountingService::postSmsTransactionToCash()`.

### Important domain distinction

| Channel | “Unmatched” means | Ledger effect on match |
|---------|-------------------|-------------------------|
| **Bank file ↔ Operations** | Ops row has no bank CSV evidence | Ops cleared; import gets domain FK; **master bank** leg per import line |
| **SMS** (today) | `member_id` is null | Post to **member + master cash** (no bank leg) |
| **SMS ↔ Bank** (proposed) | SMS has no linked bank line(s) | **Link only** if both already posted; no double cash credit |

SMS and bank CSV are **parallel evidence channels** for the same real-world transfer. Matching should link them, not post cash twice.

---

## 2. Target architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ Bank clearing                                                    │
│   Synthetic operational rows ←→ Imported bank CSV lines          │
│   bank_clearance_match_groups (1:1 / 1:N / N:1)                 │
└───────────────────────────────┬─────────────────────────────────┘
                                │
┌───────────────────────────────▼─────────────────────────────────┐
│ SMS clearing                                                     │
│   SMS transaction rows ←→ Imported bank CSV lines                  │
│   sms_clearance_match_groups (1:1 / 1:N / N:1)                   │
└───────────────────────────────┬─────────────────────────────────┘
                                │
┌───────────────────────────────▼─────────────────────────────────┐
│ Reconciliation                                                   │
│   Nightly batch exceptions · Snapshot integrity · Resolution UI  │
└─────────────────────────────────────────────────────────────────┘
```

### Shared principles (defaults)

- Full-line amounts only (no partial allocation)
- Both directions: 1→N and N→1 (not true N:M in v1)
- Auto-match stays **1:1 only**
- Unmatch reverses the **whole group**
- One master-bank ledger entry per imported bank line (unchanged)
- SMS match is **evidence linking**; cash posting stays on existing SMS post flow

---

## 3. Phase breakdown

### Phase 0 — Bank clearing hygiene (small, do first)

**Goal:** Fix edge cases before building SMS on the same patterns.

| # | Task | Why |
|---|------|-----|
| 0.1 | On `unmatchClearedGroup()`, clear stale `fund_postings.bank_transaction_id` (and siblings) when ops rows become uncleared | Prevents orphan FKs after group unmatch |
| 0.2 | Add **Unmatch** / **Unmatch group** row action on bank clearing (history + cleared rows) | Admins shouldn’t need business-day rollback to fix mistakes |
| 0.3 | Presenter: show group size + linked refs in reconciliation exception detail for bank rows | Visibility for multi-line matches |
| 0.4 | Tests: unmatch hygiene, Filament action smoke, posting integrity after group unmatch | Regression guard |

**Exit criteria:** Admin can unmatch a group from UI; no stale posting FKs after unmatch; tests green.

---

### Phase 1 — Bank reconciliation: full 1:N / N:1 coverage

**Goal:** Reconciliation understands group matches, not just 1:1 pairs.

| # | Task | Details |
|---|------|---------|
| 1.1 | **Enhanced scan** in `BankClearingMatchService` | Add `scanGroupMatchHints()`: detect when sum of uncleared imports ≈ one ops row (or vice versa) within tolerance + date window. Emit structured hints, not auto-match. |
| 1.2 | **New exception codes** | e.g. `RECON_SPLITTABLE_BANK_MATCH` (high): “N bank lines sum to 1 ops — use Match to multiple”. Distinct from `RECON_AMBIGUOUS_MATCH` (multiple 1:1 candidates). |
| 1.3 | **Presenter + actions** | Deep-link to bank clearing with pre-filtered refs; action opens group-match modal where applicable. |
| 1.4 | **`resolveAmbiguousBankMatchGroup()`** in `ReconciliationCorrectionService` | Accept multiple imported IDs **or** multiple operational IDs → `clearMatchGroup()`. Keep existing 1:1 resolver. |
| 1.5 | **Auto-resolve rules** | Do **not** auto-resolve group hints (manual only, same as ambiguous). |
| 1.6 | **Amount mismatch check** | Extend `AMOUNT_MISMATCH` to consider group totals when `bank_clearance_match_group_id` is set. |
| 1.7 | Tests | Scan hints, group resolution from recon, integrity after recon-driven group match |

**Explicitly out of scope (v1):** True N:M (2↔2). Document as Phase 6 if needed.

**Exit criteria:** Split-line scenarios surface actionable recon exceptions; resolver can clear groups; nightly batch doesn’t false-positive on intentional group matches.

---

### Phase 2 — SMS reconciliation foundation (no cross-match yet)

**Goal:** Turn SMS from “skipped” into a first-class reconciled domain.

| # | Task | Details |
|---|------|---------|
| 2.1 | **`collectSmsTransactionPostingIntegrityIssues()`** | For each `posted_at IS NOT NULL` row: verify member cash + master cash mirror legs exist, amounts/types/member match. |
| 2.2 | **Activate pipeline metrics** | Replace hardcoded `sms_unposted_count/amount` with real open-queue counts. |
| 2.3 | **`ReconciliationService::reconcileSmsClearing()`** | New nightly checks (see table below). |
| 2.4 | **Presenter + health summary** | `sms_clearing` domain; links to SMS clearing workspace tabs; Arabic strings. |
| 2.5 | **Remove “not used in SaaS” stub** | Update `ReconciliationSnapshotTest` to expect live checks when SMS data exists. |
| 2.6 | **Correction paths** | `assignMemberAndPost`, `reverseSmsPost` for recon exceptions. |

**Nightly SMS exception codes (Phase 2):**

| Code | Trigger |
|------|---------|
| `SMS_UNPOSTED_BACKLOG` | Stale unposted rows (configurable age) |
| `SMS_MEMBER_UNMATCHED` | `member_id` null past threshold |
| `SMS_POSTED_WITHOUT_LEDGER` | Posted but missing ledger legs |
| `SMS_DUPLICATE_RISK` | Optional — near-duplicate unposted rows |

**Exit criteria:** Snapshot and nightly batch report real SMS backlog and integrity issues; UI links work.

---

### Phase 3 — SMS ↔ bank schema & service (1:1 + groups)

**Goal:** Mirror bank group matching for SMS ↔ bank CSV lines.

#### 3a. Schema

```
sms_clearance_match_groups
  id, cleared_at, timestamps

sms_transactions (add)
  sms_clearance_match_group_id  nullable FK
  is_bank_cleared               boolean default false
  bank_cleared_at               nullable datetime

bank_transactions (add, for SMS-anchored imports)
  sms_clearance_match_group_id  nullable FK
```

**Recommendation:** Reuse the same pattern as bank — `sms_clearance_match_groups` + FK on both sides — rather than a separate junction table, unless audit needs per-link amount notes.

#### 3b. Service: `SmsBankClearingMatchService`

Mirror `BankClearingMatchService` API:

| Method | Behavior |
|--------|----------|
| `clearMatchPair(SmsTransaction, BankTransaction)` | 1:1 link; validate amounts/dates; mark both cleared in group of 1 |
| `clearMatchGroup(Collection $sms, Collection $bank)` | 1:N or N:1; sum validation |
| `unmatchClearedGroup(SmsTransaction\|BankTransaction)` | Reverse whole group |
| `findGroupMatchBankCandidates(SmsTransaction)` | All eligible bank imports (no single-line amount filter) |
| `findGroupMatchSmsCandidates(BankTransaction)` | All eligible SMS rows |
| `scanMatchExceptions()` | 1:1 ambiguous/unmatched for nightly recon |
| `scanGroupMatchHints()` | Combinatorial sum hints |

#### 3c. Posting rules (critical)

| SMS state | Bank state | On match |
|-----------|------------|----------|
| Unposted, member known | Unposted import | **Default:** Link first; posting stays separate. Optional: atomic “match & post” action. |
| Posted | Unposted import | Link; bank line uses normal bank clearance / post-as |
| Posted | Posted (orphan) | Link only — recon auto-resolve candidate for 1:1 |
| Unposted, no member | Any | Block match; recon shows `SMS_MEMBER_UNMATCHED` |

**Ledger rule:** SMS match must **never** call `creditMemberCash` again if `posted_at` is set. Bank match must **never** skip `postMatchedImportToMasterBankLedger` for imports that need master bank legs.

#### 3d. UI

| Location | Action |
|----------|--------|
| SMS row | **Match to bank line** (1:1), **Match to multiple bank lines** (1→N) |
| Bank file row | **Match to SMS** (1:1), **Match to multiple SMS** (N→1 from bank side) |
| Both | Running total + tolerance indicator (same as bank group modals) |
| Cleared rows | **Unmatch group** |

#### 3e. Queue filters

Extend `SmsClearingQueueFilter`:

- `UnmatchedBank` — posted (or member-known), not bank-cleared
- `ReadyToMatch` — member + amount, eligible for bank link

Extend bank clearing filter:

- `UnmatchedSms` — import with no SMS link (optional cross-channel tab)

**Exit criteria:** Can manually group-match SMS↔bank; unmatch restores both sides; no duplicate cash credits in tests.

---

### Phase 4 — SMS reconciliation: cross-channel exceptions

**Goal:** Reconciliation covers SMS↔bank the same way as ops↔bank.

| Code | Trigger |
|------|---------|
| `SMS_RECON_UNMATCHED_BANK_LINE` | SMS posted, no bank link, past stale window |
| `SMS_RECON_AMBIGUOUS_BANK_MATCH` | Multiple bank candidates for one SMS (1:1 scan) |
| `SMS_RECON_SPLITTABLE_BANK_MATCH` | Sum of N bank lines ≈ one SMS (hint) |
| `BANK_RECON_UNMATCHED_SMS_LINE` | Bank import with no SMS link where SMS channel expected |
| `SMS_BANK_AMOUNT_MISMATCH` | Linked pair/group totals diverge beyond tolerance |
| `SMS_BANK_CROSS_POSTED_DRIFT` | SMS posted + bank cleared but amounts/members disagree |

**Resolution actions:**

- `resolveSmsBankMatch` (1:1) → `clearMatchPair`
- `resolveSmsBankMatchGroup` → `clearMatchGroup`
- Link to SMS / bank clearing workspaces with refs pre-selected

**Exit criteria:** Nightly batch raises cross-channel exceptions; resolver can clear them; snapshot includes SMS↔bank integrity.

---

### Phase 5 — Rollback, audit & unified reporting

| # | Task |
|---|------|
| 5.1 | `BusinessDayWindowRollbackService`: route SMS/bank group members through `SmsBankClearingMatchService::unmatchClearedGroup()` |
| 5.2 | Undo SMS post: if bank-linked, unmatch group first |
| 5.3 | Audit log entries for group match/unmatch (both channels) |
| 5.4 | Reconciliation PDF / overview: SMS pipeline row live; group match counts in bank + SMS health cards |
| 5.5 | `FiscalClosePurgeService`: handle orphan `sms_clearance_match_groups` (same as bank groups) |

---

### Phase 6 — Optional future: true N:M

Only if business requires (e.g. 2 SMS alerts ↔ 2 bank lines in one settlement):

- Relax `clearMatchGroup()` validation on both services
- Ensure sum equality across both sides
- Unmatch reverses all legs
- Reconciliation scan for N:M hints (high complexity)

**Recommendation:** Defer unless a tenant demonstrates real 2↔2 cases.

---

## 4. Product decisions (defaults)

| # | Decision | Default |
|---|----------|---------|
| 1 | **SMS↔bank match scope** | **A)** SMS rows match **bank CSV imports only** (parallel evidence) |
| 2 | **Post-on-match behavior** | **A)** Link only; posting stays separate |
| 3 | **Reconciliation auto-resolve** | **B)** Auto-resolve when exactly one SMS ↔ one bank candidate (1:1 only); never auto-resolve groups |

Alternatives:

- 1B: SMS matches operational synthetic rows  
- 1C: Both  
- 2B: Combined “Match & post SMS + clear bank” one-step action  
- 3A: Never auto-resolve cross-channel matches  

---

## 5. Testing strategy

| Layer | Tests |
|-------|-------|
| Unit | Sum validation, candidate queries, hint scanner |
| Feature | `SmsBankClearingMatchServiceTest` — 1:1, 1→N, N→1, unmatch, unbalanced rejection |
| Feature | `ReconciliationSmsClearingTest` — nightly exceptions, snapshot integrity |
| Feature | `ReconciliationSmsBankCrossMatchTest` — cross-channel resolve |
| Feature | Extend `BusinessDayWindowRollbackTest` — SMS↔bank group rollback |
| Feature | Extend `BankTransactionPostingIntegrityTest` — no double master bank / cash |
| Architecture | `ReconciliationExceptionPresenterTest` — new codes + actions |
| Demo data | Extend `storage/samples/bank-clearing-demo.csv` manifest OR add SMS demo + seed command |

---

## 6. Implementation order

| Phase | Scope | Estimate |
|-------|--------|----------|
| 0 | Bank hygiene (unmatch UI, FK cleanup) | 1–2 days |
| 1 | Bank recon group coverage | 2–3 days |
| 2 | SMS recon foundation (integrity + nightly) | 2–3 days |
| 3 | SMS↔bank match service + UI | 4–5 days |
| 4 | SMS↔bank recon exceptions + resolution | 2–3 days |
| 5 | Rollback, audit, reporting | ~2 days |
| 6 | True N:M (optional) | Defer |

**Total (Phases 0–5):** ~2–3 weeks with tests.

**Suggested start:** Phase 0 + Phase 2 in parallel, then Phase 3.

---

## 7. Out of scope (v1)

- Auto-match beyond **1:1** for bank and SMS
- Partial amount allocation on either channel
- Twilio / notification SMS (different subsystem)
- Expense/fee/invest bank paths that skip master-bank ledger on match — unchanged
- Replacing bank statement import with SMS-only workflows

---

## 8. Code map (planned additions)

| Area | Files |
|------|-------|
| SMS match | New `SmsBankClearingMatchService`, migration, `SmsClearanceMatchGroup` model, `SmsClearingQueueActions`, `BankClearingQueueActions` |
| SMS recon | `ReconciliationReportService`, `ReconciliationService`, `ReconciliationExceptionPresenter`, `ReconciliationExceptionActions`, `ReconciliationHealthSummary` |
| Bank gaps | `BankClearingQueueActions`, `BankClearingMatchService`, `ReconciliationCorrectionService` |
| Rollback | `BusinessDayWindowRollbackService` |
| Tests | New feature tests per phase; update `ReconciliationSnapshotTest` |

---

## 9. References

- Bank group matching migration: `database/migrations/tenant/2026_09_01_074040_create_bank_clearance_match_groups_table.php`
- Demo sample: `storage/samples/bank-clearing-demo.csv`, `bank-clearing:seed-demo` command
- SMS workspace: `app/Filament/Tenant/Resources/SmsClearing/`
- Reconciliation skipped SMS check: `ReconciliationReportService` (~L425–430)
