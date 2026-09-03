# Reconciliation + 1:M / M:1 Bank & SMS Clearing — Implementation Plan

**Audience:** Tenant admins / implementers  
**Status:** Phases 0–6 implemented; **Phase 7 (SMS-only)** — 7a–7d shipped (see §3 Phase 7)  
**Related:** [bank-clearing-work-queue-actions.md](bank-clearing-work-queue-actions.md), [bank-clearing-workspace-implementation-plan.md](bank-clearing-workspace-implementation-plan.md)

This plan closes gaps identified after bank clearance **1→N / N→1** group matching was added, extends the same model to **Bank SMS clearing**, with full reconciliation coverage for both channels, and defines an optional **Phase 7** for tenants that use **SMS-only evidence** instead of bank statement CSV import.

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

**Phases 0–6** assume both channels may coexist. **Phase 7** is a separate product mode where bank CSV import is disabled and SMS becomes the sole external evidence for clearance.

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

**Phase 7 (SMS-only tenants)** — bank CSV import disabled; operational clearance uses SMS instead of imported statement lines:

```
┌─────────────────────────────────────────────────────────────────┐
│ Evidence channel setting: sms                                    │
│   Bank CSV import · mirror-to-cash · post-as · bank:auto-match   │
│   → hidden / gated off                                           │
└───────────────────────────────┬─────────────────────────────────┘
                                │
┌───────────────────────────────▼─────────────────────────────────┐
│ SMS clearing (primary evidence)                                  │
│   SMS import → member match → post to cash (+ master bank rule)  │
│   Synthetic operational rows ←→ SmsTransaction rows              │
│   sms_ops_clearance_match_groups (1:1 / 1:N / N:1)              │
└───────────────────────────────┬─────────────────────────────────┘
                                │
┌───────────────────────────────▼─────────────────────────────────┐
│ Reconciliation (channel-aware)                                   │
│   SMS ops backlog · deposit evidence · outbound clearance       │
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

### Phase 7 — SMS-only workflows (replace bank statement import)

**Goal:** Allow a tenant to operate without bank statement CSV import. SMS alerts become the **sole external evidence** for matching synthetic operational rows (deposits, cash-outs, fees, etc.). Depends on **Phases 0–6** (group matching patterns, SMS recon foundation, rollback/audit).

**Prerequisite:** Phases 2–5 minimum (SMS recon live; group unmatch/rollback patterns proven). Phase 3–4 cross-channel SMS↔bank work remains valid for `evidence_channel = both` tenants only.

#### 7a. Evidence channel setting & UI gating

| # | Task | Details |
|---|------|---------|
| 7a.1 | **`evidence_channel` tenant setting** | Values: `bank_csv` (default) \| `sms` \| `both`. Store in `Setting` (e.g. group `system` or `reconciliation`). |
| 7a.2 | **`EvidenceChannelSettings` helper** | `usesBankCsv()`, `usesSms()`, `isSmsOnly()` — gate services, scheduler, Filament visibility. |
| 7a.3 | **Settings UI** | Radio/select on Automation or Reconciliation settings; conditional sections for `BankTemplate` vs `SmsImportTemplate`. |
| 7a.4 | **Hide bank CSV surfaces when `sms`** | `BankWorkspaceImportTableHeaderActions::bankStatementImportAction()`, bank-file queue tab, Post as…, `bank:auto-match` registry row — not rendered / not runnable. |
| 7a.5 | **Architecture test** | SMS-only fixture tenant must not expose bank CSV import actions. |

**Exit criteria:** Setting persists; bank import hidden for SMS-only; no behavior change to ledger yet.

#### 7b. Operational ↔ SMS clearance & master bank

| # | Task | Details |
|---|------|---------|
| 7b.1 | **Schema** | `sms_ops_clearance_match_groups` + FK on `sms_transactions` and synthetic `bank_transactions` (ops rows). Optional `sms_transaction_id` on domain rows (`fund_postings`, `cash_out_requests`, …). |
| 7b.2 | **`SmsOperationalClearingMatchService`** (new) | Mirror `BankClearingMatchService` API but match **ops ↔ SMS** (not ops ↔ CSV import). Methods: `clearMatchPair`, `clearMatchGroup`, `unmatchClearedGroup`, candidate queries, `scanMatchExceptions`, `scanGroupMatchHints`. |
| 7b.3 | **Master bank posting rule** | **Default:** on ops↔SMS clearance, post **master bank** leg (same semantics as `postMatchedImportToMasterBankLedger`, anchored to SMS evidence not CSV). Document in accounting rules. |
| 7b.4 | **No double cash** | SMS already posted → clearance is **link + ops cleared + master bank** only; never second `creditMemberCash`. |
| 7b.5 | **Extend `SmsImportService`** | Optional auto-link to pending ops row on post when exactly one candidate (1:1). |
| 7b.6 | **`SmsClearingQueueService` filters** | Add `UnmatchedOps`, `ReadyToClearOps`; hide `UnmatchedBank` when `isSmsOnly()`. |
| 7b.7 | **Gate legacy paths** | `FundFlowService::mirrorToCash`, `BankImportPostAsService`, `BankImportService` — throw or skip when `isSmsOnly()`. |

**Exit criteria:** Deposit accept → uncleared ops row → SMS post → ops↔SMS match clears ops and posts master bank; tests prove no duplicate cash.

#### 7c. Reconciliation, scheduler & reporting (channel-aware)

| # | Task | Details |
|---|------|---------|
| 7c.1 | **Branch nightly batch** | Skip `reconcileBankClearing()` and SMS↔bank cross-channel checks when `isSmsOnly()`. Run new `reconcileSmsOperationalClearing()`. |
| 7c.2 | **New exception codes** | See table below. Retire or suppress bank-import-centric codes for SMS-only tenants. |
| 7c.3 | **`ReconciliationCorrectionService`** | `resolveSmsOpsMatch`, `resolveSmsOpsMatchGroup`; deep-links to SMS clearing workspace. |
| 7c.4 | **Replace deposit evidence check** | Successor to `CASH_DEPOSIT_UNBANKED`: e.g. `CASH_DEPOSIT_UNEVIDENCED` — accepted `FundPosting` with no SMS ops link past `cash_deposit_unevidenced_days` (rename or alias `cash_deposit_unbanked_days`). |
| 7c.5 | **`ReconciliationReportService`** | Replace `bank_uncleared_*` pipeline metrics with `sms_ops_uncleared_*` when SMS-only. |
| 7c.6 | **Scheduler** | Gate `bank:auto-match`. Add `sms:auto-match-ops` (1:1 only) or extend nightly recon auto-resolve for unique ops↔SMS pairs. |
| 7c.7 | **Dashboard / fiscal close** | `TreasuryForecastService`, `FiscalCloseReadinessService`, `BankAccountsInsightsService` — SMS backlog gauges instead of bank import post-rate. |

**Nightly SMS-only exception codes (Phase 7c):**

| Code | Trigger |
|------|---------|
| `SMS_OPS_UNMATCHED` | Posted SMS, no ops link, past stale window |
| `OPS_RECON_UNMATCHED_SMS` | Uncleared ops row, SMS channel expected, no SMS candidate |
| `SMS_OPS_AMBIGUOUS_MATCH` | Multiple 1:1 SMS candidates for one ops row |
| `SMS_OPS_SPLITTABLE_MATCH` | Combinatorial sum hint (N SMS ↔ 1 ops or vice versa) |
| `SMS_OPS_AMOUNT_MISMATCH` | Linked pair/group totals diverge beyond tolerance |
| `CASH_DEPOSIT_UNEVIDENCED` | Accepted deposit with no SMS ops evidence (replaces `CASH_DEPOSIT_UNBANKED` for SMS-only) |

**Exit criteria:** Nightly batch and snapshot are meaningful without bank imports; resolver clears ops↔SMS exceptions; `bank:auto-match` does not run for SMS-only.

#### 7d. Outbound flows, migration & docs

| # | Task | Details |
|---|------|---------|
| 7d.1 | **Outbound clearance policy** | Cash-outs, expense/fee/invest disbursements: define SMS evidence rules (debit-shaped SMS alerts, manual match, or retain ops rows with extended stale windows). Implement per `MemberCashOutService` and disbursement services. |
| 7d.2 | **Rollback** | `BusinessDayWindowRollbackService`: ops↔SMS group unmatch; undo SMS post unmatch ops group first. |
| 7d.3 | **Channel switch migration** | Backfill plan for tenant moving `bank_csv` → `sms` or `both` → `sms`: open ops rows, posted SMS without links, orphan bank imports. |
| 7d.4 | **UI consolidation (optional)** | Single “Treasury clearing” nav entry merging SMS queue + ops-match tabs; redirect legacy `/bank-accounts?tab=imports` for SMS-only. |
| 7d.5 | **Docs & operator SOP** | Update `manual-accountant.md`, `fund-flow-user-guide.md`, `accounting-master-member-sync.mdc` for SMS-only path. |

**Exit criteria:** Outbound flows documented and tested for at least cash-out; migration runbook exists; user guides describe SMS-only SOP.

**Explicitly out of scope (Phase 7 v1):**

- True N:M ops↔SMS (reuse Phase 6 machinery if needed later)
- Replacing **manual** master bank adjustments or external `bank_statement_vs_book` declared balance checks
- Twilio / member notification SMS

**Estimate:** ~3–5 weeks after Phases 0–6, depending on outbound scope.

---

## 4. Product decisions (defaults)

| # | Decision | Default |
|---|----------|---------|
| 1 | **SMS↔bank match scope** | **A)** SMS rows match **bank CSV imports only** (parallel evidence) |
| 2 | **Post-on-match behavior** | **A)** Link only; posting stays separate |
| 3 | **Reconciliation auto-resolve** | **B)** Auto-resolve when exactly one SMS ↔ one bank candidate (1:1 only); never auto-resolve groups |

Alternatives:

- 1B: SMS matches operational synthetic rows — **Phase 7 default for `evidence_channel = sms`**
- 1C: Both — **`evidence_channel = both`**
- 2B: Combined “Match & post SMS + clear bank” one-step action  
- 3A: Never auto-resolve cross-channel matches  

**Phase 7 decisions (SMS-only tenants):**

| # | Decision | Default |
|---|----------|---------|
| 7.1 | **Evidence channel** | **`bank_csv`** for new tenants; per-tenant override |
| 7.2 | **Master bank on SMS evidence** | **Post master bank on ops↔SMS clearance** (not on SMS cash post alone) |
| 7.3 | **Inbound deposit path** | Accept posting → uncleared ops row → SMS import/post → ops↔SMS match (no bank CSV) |
| 7.4 | **Outbound evidence** | **Manual ops↔SMS match** for cash-outs in v1; auto-match 1:1 where unique |
| 7.5 | **Cross-channel SMS↔bank** | **Disabled** when `evidence_channel = sms` |
| 7.6 | **External statement check** | Keep optional `bank_statement_vs_book` (declared balance) — orthogonal to import channel |

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
| Feature (Phase 7) | `SmsOperationalClearingMatchServiceTest` — ops↔SMS 1:1, 1→N, N→1, unmatch, master bank leg |
| Feature (Phase 7) | `ReconciliationSmsOnlyChannelTest` — nightly exceptions, channel gating, no bank import |
| Feature (Phase 7) | `EvidenceChannelSettingsTest` — UI/service gates per channel |
| Architecture (Phase 7) | SMS-only tenant exposes no bank CSV import actions |

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
| 7a | Evidence channel setting + UI gating | 2–3 days |
| 7b | Ops↔SMS clearance + master bank | 1–2 weeks |
| 7c | Channel-aware recon + scheduler + reporting | 3–5 days |
| 7d | Outbound flows + migration + docs | 3–5 days |

**Total (Phases 0–5):** ~2–3 weeks with tests.

**Total (Phase 7, after 0–6):** ~3–5 weeks with tests.

**Suggested start:** Phase 0 + Phase 2 in parallel, then Phase 3. Phase 7 only after a tenant commits to SMS-only; start with 7a (gating) before 7b (ledger).

---

## 7. Out of scope (Phases 0–6)

- Auto-match beyond **1:1** for bank and SMS
- Partial amount allocation on either channel
- Twilio / notification SMS (different subsystem)
- Expense/fee/invest bank paths that skip master-bank ledger on match — unchanged
- SMS-only workflows — **Phase 7** (this plan)

---

## 8. Code map (planned additions)

| Area | Files |
|------|-------|
| SMS match | New `SmsBankClearingMatchService`, migration, `SmsClearanceMatchGroup` model, `SmsClearingQueueActions`, `BankClearingQueueActions` |
| SMS recon | `ReconciliationReportService`, `ReconciliationService`, `ReconciliationExceptionPresenter`, `ReconciliationExceptionActions`, `ReconciliationHealthSummary` |
| Bank gaps | `BankClearingQueueActions`, `BankClearingMatchService`, `ReconciliationCorrectionService` |
| Rollback | `BusinessDayWindowRollbackService` |
| Phase 7 — channel | New `EvidenceChannelSettings`, `Setting` keys, `Settings.php` (Filament), `ScheduledJobRegistry` gates |
| Phase 7 — ops↔SMS | New `SmsOperationalClearingMatchService`, `SmsOpsClearanceMatchGroup` model, migration, extend `SmsClearingQueueService`, `SmsClearingQueueActions` |
| Phase 7 — clearance hooks | `FundPostingService`, `MemberCashOutService`, disbursement services, `FundFlowService`, `BankImportService` (gates) |
| Phase 7 — recon | `ReconciliationService`, `ReconciliationCorrectionService`, `ReconciliationReportService`, `TreasuryForecastService`, `FiscalCloseReadinessService` |
| Phase 7 — jobs | New `SmsAutoMatchOpsCommand` (or extend nightly recon), gate `BankAutoMatchCommand` |
| Tests | New feature tests per phase; update `ReconciliationSnapshotTest`; Phase 7 channel + ops↔SMS suites |

---

## 9. References

- Bank group matching migration: `database/migrations/tenant/2026_09_01_074040_create_bank_clearance_match_groups_table.php`
- Demo sample: `storage/samples/bank-clearing-demo.csv`, `bank-clearing:seed-demo` command
- SMS workspace: `app/Filament/Tenant/Resources/SmsClearing/`
- Reconciliation skipped SMS check: `ReconciliationReportService` (~L425–430)
- Evidence channel (Phase 7): new `EvidenceChannelSettings`; gate pattern in `TenantAwareScheduledCommand` + `AutomationSchedulerGate`
- Ops clearance today: synthetic rows from `FundPostingService`, `MemberCashOutService`, disbursement services → `BankClearingMatchService`
- SMS post today: `AccountingService::postSmsTransactionToCash()` (member + master cash only)
- Operator guides to update for Phase 7: `docs/manual-accountant.md`, `docs/fund-flow-user-guide.md`
