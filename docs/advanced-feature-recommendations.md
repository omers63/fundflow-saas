# Advanced feature recommendations & implementation plans

| Field | Value |
|-------|-------|
| **Version** | 1.0 |
| **Status** | Proposed (not started) |
| **Date** | September 2026 |
| **Audience** | Product, operations, and developers planning next major work |
| **Related** | [fund-flow-implementation.md](fund-flow-implementation.md) · [reconciliation-bank-sms-clearing-plan.md](reconciliation-bank-sms-clearing-plan.md) · [loan-delinquency-workflow.md](loan-delinquency-workflow.md) · [fiscal-year-end-close.md](fiscal-year-end-close.md) · [communications-platform.md](communications-platform.md) · [accounting master/member sync](../.cursor/rules/accounting-master-member-sync.mdc) |

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [What already exists (do not rebuild)](#2-what-already-exists-do-not-rebuild)
3. [Recommendations by priority](#3-recommendations-by-priority)
4. [Suggested sequencing](#4-suggested-sequencing)
5. [Implementation plans](#5-implementation-plans)
6. [Shared constraints](#6-shared-constraints)
7. [Out of scope](#7-out-of-scope)

---

## 1. Executive summary

FundFlow SaaS already covers the hard core of a mutual fund: double-entry ledgers with master/member pool mirrors, contribution and EMI cycles, delinquency, guarantors, bank/SMS clearing, fiscal close, WhatsApp/web-push notifications, Filament Shield roles, and ops overview dashboards.

The highest-leverage next work reduces **manual money handling**, then adds **governance**, then **risk and integrations**. Payment collection and bulk payouts compound: less admin time, fewer reconciliation exceptions, clearer audit trails.

This document ranks ten advanced features and documents an implementation plan for each against the current architecture.

---

## 2. What already exists (do not rebuild)

| Area | Existing building blocks |
|------|--------------------------|
| Ledger & mirrors | `AccountingService`, `FundPostingService`, `MemberCashOutService`, `LoanLedgerService` |
| Bank / SMS clearing | `BankClearingMatchService`, `SmsBankClearingMatchService`, `InboundPaymentService`, `OutboundPaymentService` |
| Delinquency | `LoanDelinquencyService`, `MemberDelinquencyEvaluator`, `MemberLatePaymentHistoryEvaluator` |
| Invest / expense | `MasterInvestInService`, `MasterInvestOutService`, `MasterInvestReturnService`, `MasterExpenseDisbursementService` |
| Forecasts | `TreasuryForecastService`, `CycleForecastService` |
| Comms | Twilio, WhatsApp channel, web-push, notification preferences & logs |
| Auth / roles | Filament Shield, `permission.php`, tenant/member/admin panels |
| Close | Fiscal year close purge / export flows |
| Member UX | Portal dashboard, loan calculator, statements (PDF via dompdf) |

---

## 3. Recommendations by priority

Ordered by impact on trust, money movement, and retention.

### 1. Payment-gateway collection (Mada / Apple Pay / SADAD) with auto-posting

**Why first:** Every inflow today depends on a member bank transfer plus admin matching of a statement or SMS line. That is the largest operational cost and a primary source of reconciliation exceptions.

**Outcome:** Member pays contribution, EMI, fee, or arrears in-portal; webhook posts cash with master mirror and an auto-cleared bank evidence line; admin only handles exceptions.

### 2. Bulk disbursement files (SARIE / bank bulk-transfer export)

**Why:** Cash-outs, fund-outs, loan disbursements, and expense payouts are approved in-app but paid one-by-one in the bank portal.

**Outcome:** Dual-controlled bulk payment file from approved `OutboundPayment`s; batch lifecycle through bank ack into existing clearance.

### 3. Governance: annual meeting, votes, and board decisions

**Why:** Jamʿiyas need documented decisions for tier/fee changes, large expenses, and member exclusions. Nothing tracks that today.

**Outcome:** Meetings, motions, member votes with quorum; settings/expense mutations above a threshold require an approved motion.

### 4. Profit / return distribution engine (extends Master Invest)

**Why:** Invest in/out and returns are recorded, but returns are not distributed to members.

**Outcome:** Time-weighted pro-rata distribution run with preview → approve → reversible batch postings via fund mirrors; ties into fiscal close.

### 5. Risk scoring and early-warning (rules first, ML later)

**Why:** Delinquency is mostly detected after the fact; late-payment signals already exist.

**Outcome:** Per-member and guarantor risk scores on approval, list filters, and a weekly watch-list digest.

### 6. Public REST API + webhooks (Sanctum, versioned)

**Why:** No tenant API surface exists for BI tools or partner systems.

**Outcome:** Read-only v1 with tenant-scoped tokens; HMAC webhooks for key lifecycle events.

### 7. Receipt OCR and three-way match

**Why:** Deposit accept still requires an admin to read attachments and match manually.

**Outcome:** OCR extracts amount/IBAN/reference/date; high-confidence three-way match (receipt + SMS + statement) auto-accepts.

### 8. Member-facing savings goals and planning

**Why:** Portal is transactional; goals improve engagement and contribution consistency.

**Outcome:** Goals with progress from fund balance; loan-readiness nudges from eligibility + calculator.

### 9. Security hardening for admins

**Why:** High-value disbursement and export actions need stronger guarantees for PDPL and ops trust.

**Outcome:** TOTP/passkey 2FA, device sessions, step-up re-auth for bulk payouts; member data-export / closure requests.

### 10. Tenant-level plan & billing (SaaS side)

**Why:** Only valuable once external tenants are paying.

**Outcome:** Plans by member count/features, usage metering, invoices, grace → read-only on non-payment.

---

## 4. Suggested sequencing

```text
Phase A (money rails)     →  #1 Payment gateway  →  #2 Bulk disbursement  →  #9 Admin 2FA (step-up with #2)
Phase B (governance)      →  #3 Meetings/votes   →  #4 Profit distribution (needs #3 for approval of large runs)
Phase C (intelligence)    →  #5 Risk scoring     →  #7 OCR / three-way match
Phase D (platform)        →  #6 API + webhooks   →  #8 Member goals      →  #10 SaaS billing (when selling)
```

**Rationale:** Payment rails and dual-control payouts cut daily ops load first. Governance unlocks safe distribution of investment returns. Risk and OCR improve quality of remaining manual work. API and goals expand the platform; SaaS billing waits for commercial need.

---

## 5. Implementation plans

### 5.1 Payment-gateway collection

#### Goal

Members pay owed amounts in-portal; ledger posts automatically with pool mirrors; bank evidence is cleared without a human match when the gateway settlement is known.

#### Architecture

| Piece | Approach |
|-------|----------|
| Provider | Moyasar or HyperPay (Mada + Apple Pay + STC Pay); SADAD as biller + file import |
| Models | `GatewayPayment` (intent, amount, purpose, member_id, provider_ref, status); reuse `InboundPayment` for settlement |
| Posting | Webhook → verify signature → `FundPostingService::accept()` / contribution-EMI collectors with `creditMemberCashWithMasterMirror` (or debit path for fees already owed) |
| Clearance | Create cleared `BankTransaction` from gateway settlement payout, or uncleared until settlement file arrives |
| Reconciliation | New domain `gateway_settlement`: gateway payout amount vs sum of gateway-posted lines |

#### Phases

1. **Provider adapter** — config keys, webhook route (tenant-aware), signature verify, idempotent processing by `provider_ref`.
2. **Pay-now UX** — member portal actions on contribution due, EMI due, fee arrears, open deposit request; amount locked to owed balance.
3. **Posting paths** — map purpose → existing services; never invent one-off cash legs; update invariants/tests.
4. **Settlement import** — daily gateway settlement CSV → match to `GatewayPayment` batch → clear bank lines.
5. **SADAD** — biller registration + SADAD payment file into bank-import / inbound pipeline.
6. **Ops** — admin list of gateway payments; exception queue when webhook amount ≠ owed.

#### Key files (expected)

- `app/Services/Gateway/` adapters + `GatewayPaymentPostingService`
- `app/Http/Controllers/Webhooks/GatewayWebhookController.php`
- Member portal: pay actions on contribution / loan / fee surfaces
- Pest: webhook idempotency, mirror posting, settlement match

#### Success criteria

- Happy path: pay → cash credited + master cash mirror + notification + receipt PDF; no open bank match item.
- Duplicate webhook does not double-post.
- Reconciliation raises when settlement payout ≠ sum of accepted gateway payments.

#### Dependencies / risks

- Merchant account & SADAD registration lead time.
- Partial payments and over-payments need explicit policy (reject vs apply excess to cash).

---

### 5.2 Bulk disbursement files

#### Goal

Approved outbound money leaves the fund via a bank bulk file with dual control, then clears through the existing bank-clearing pipeline.

#### Architecture

| Piece | Approach |
|-------|----------|
| Source | Approved `OutboundPayment` / cash-out / fund-out / loan disbursement / expense rows awaiting bank payout |
| Models | `DisbursementBatch` (status: draft → pending_approval → generated → uploaded → acked → cleared); `DisbursementBatchItem` |
| Files | Templates: Al Rajhi / SNB / Riyad; ISO 20022 `pain.001` generic |
| IBAN | Validate MOD-97 on member/payee profile before batch include |
| Dual control | Builder ≠ approver; Shield permission `disbursement_batches.approve`; step-up auth (see §5.9) |
| Clearance | Bank return / statement match via `BankTransactionClearanceService`; no extra ledger on clear |

#### Phases

1. **IBAN on profiles** + validation helper.
2. **Batch builder** Filament page: select eligible outbound rows, preview totals, generate file (download audited).
3. **Approve / download** second-admin gate; lock batch contents.
4. **Ack / import** optional bank ack file; mark items paid.
5. **Clear** match statement lines to batch items (1:N group match pattern already exists).

#### Key files (expected)

- `app/Services/Disbursements/DisbursementBatchService.php`
- `app/Services/Disbursements/BankBulkFileExporter.php` (+ format drivers)
- Tenant page under Fund Management / Bank Accounts
- Pest: dual-control, IBAN reject, export bytes, clear without double ledger

#### Success criteria

- Cannot download file without second approval.
- Clearing a statement against a batch item does not post a second cash debit.
- Audit log records builder, approver, download time, hash of file.

#### Dependencies / risks

- Bank-specific file quirks; start with one bank template + pain.001.
- Partial bank rejects need item-level status.

---

### 5.3 Governance: meetings, motions, votes

#### Goal

Material decisions are proposed, voted (or board-approved), and only then applied to settings or large disbursements.

#### Architecture

| Piece | Approach |
|-------|----------|
| Models | `Meeting`, `Motion`, `Vote`, `MotionAttachment` |
| Motion types | `setting_change`, `expense_approval`, `member_status`, `distribution_run`, `generic` |
| Quorum | Configurable % of active members (or board-only mode) |
| Enforcement | Before `Setting::set()` or expense above threshold: require `Motion` status `approved` |
| Portal | Member vote UI; results locked after close; minutes PDF (dompdf) |

#### Phases

1. **Data model + admin CRUD** for meetings/motions (no enforcement yet).
2. **Voting** member portal + quorum calculator + lock.
3. **Enforcement hooks** on settings save and expense disburse.
4. **Minutes PDF** + notification of open motions.
5. **Proxy / absentee** (optional follow-on).

#### Success criteria

- Changing loan interest above threshold without approved motion is blocked.
- Vote tally and voter list are auditable; cannot re-open after minutes published without a new motion.

#### Dependencies / risks

- Legal wording of bylaws differs per tenant — keep rules configurable, not hard-coded Saudi-only text.

---

### 5.4 Profit / return distribution

#### Goal

Investment returns sitting on master invest/fund are distributed to members by time-weighted fund balance, with reversible batch posting.

#### Architecture

| Piece | Approach |
|-------|----------|
| Input | Period start/end, amount to distribute (or % of recorded returns), exclusion rules |
| Allocation | Average daily fund balance (or month-end snapshots if daily too heavy) among eligible members |
| Posting | Per member: `creditMemberFundWithMasterMirror()`; batch id on reference |
| Governance | Large runs require approved motion (§5.3) |
| Fiscal close | Block year close if draft/approved-unposted distribution exists |

#### Phases

1. **Eligibility + preview** (read-only allocation table).
2. **Approve + post** transactional batch; notifications + statement lines.
3. **Reverse batch** (paired debits mirrors).
4. **Close integration** + reports export.

#### Accounting rules (must follow)

- Post **master fund first**, then member fund (same as other fund credits).
- Do **not** put `member_id` on master cash legs.
- No bank statement line unless cash is also moved (default: fund-only distribution).

#### Success criteria

- Sum of member credits equals distribution amount within rounding policy (last-member residual).
- Reverse restores balances and clears batch status.
- Nightly pool-drift checks remain clean.

#### Dependencies / risks

- Needs clear product rule: distribute from master fund vs from invest returns account.
- Rounding and mid-period joiners.

---

### 5.5 Risk scoring & early warning

#### Goal

Surface risk before approval and delinquency, using existing evaluators first.

#### Architecture

| Piece | Approach |
|-------|----------|
| Signals | Late-payment history, cash-cover vs required contribution/EMI, active loan load, guarantor exposure, household size |
| Output | Score 0–100 + band (low/medium/high/critical); factors JSON for explainability |
| Cache | `TenantRuntimeCache` / short TTL; bust on repayment, contribution post, loan status change |
| UX | Loan approval preview badge; members list column + filter; weekly admin digest |
| Guarantor | Aggregate outstanding guaranteed; block new guarantee above threshold |

#### Phases

1. **Rules engine** `MemberRiskScoreService` + unit tests against fixtures.
2. **Wire loan approval** + member list.
3. **Watch-list digest** notification.
4. **Optional ML** later — only if labeled outcomes justify it; keep rules as fallback.

#### Success criteria

- Score is explainable (list of factors).
- High band can block or require override (reuse eligibility override pattern).

---

### 5.6 Public REST API + webhooks

#### Goal

External tools can read tenant data and receive event notifications securely.

#### Architecture

| Piece | Approach |
|-------|----------|
| Auth | Laravel Sanctum personal access tokens; abilities mapped from Shield permissions |
| Routes | `routes/tenant_api.php` (or tenancy-aware `api/v1`); versioned resources |
| Resources | Members, balances, contributions, loans, statements (read-only first) |
| Webhooks | `WebhookEndpoint` + delivery log; HMAC-SHA256; retries with backoff; events: contribution.posted, loan.approved, recon.exception.raised, etc. |

#### Phases

1. Sanctum + ability middleware + OpenAPI stub.
2. Read endpoints + Pest feature tests per resource.
3. Webhook subscriptions + signed delivery worker.
4. Write endpoints only after ops demand (and dual-control for money writes).

#### Success criteria

- Token cannot cross tenants.
- Webhook signature verified in contract tests; failed deliveries visible in admin UI.

---

### 5.7 Receipt OCR & three-way match

#### Goal

Reduce manual deposit matching by extracting receipt fields and auto-accepting when receipt, SMS, and bank line agree.

#### Architecture

| Piece | Approach |
|-------|----------|
| OCR | Adapter interface (`ReceiptOcrDriver`); Tesseract local or cloud vision |
| Extract | amount, date, IBAN/account, reference |
| Match | Extend bank clearing suggestion scorer; confidence ≥ threshold + SMS agree → auto `accept` |
| Fallback | Below threshold → existing queue with pre-filled fields |

#### Phases

1. Driver + store extraction on `FundPosting` / attachment meta.
2. Suggestion UI in bank clearing.
3. Auto-accept policy flag (off by default per tenant).
4. Metrics: auto-accept rate, false-accept incidents.

#### Success criteria

- Auto-accept never posts without matching amount within tolerance.
- Admins can disable auto-accept and still see suggestions.

---

### 5.8 Member savings goals & planning

#### Goal

Give members a forward-looking reason to keep contributing.

#### Architecture

| Piece | Approach |
|-------|----------|
| Models | `MemberSavingsGoal` (target amount, target date, linked to fund balance) |
| Progress | Fund balance / target; projected hit date from scheduled monthly contribution |
| Nudges | Notification when behind; “increase monthly by X” using contribution settings |
| Loan-ready | Surface months-to-eligibility via `MemberLoanCalculatorService` + eligibility rules |

#### Phases

1. CRUD + portal widget on dashboard.
2. Projection + nudges.
3. Loan-readiness card.

#### Success criteria

- Goal progress uses live fund balance (same as workspace summary).
- No ledger side effects from creating a goal.

---

### 5.9 Admin security hardening

#### Goal

Protect high-value actions and support PDPL member rights.

#### Architecture

| Piece | Approach |
|-------|----------|
| 2FA | TOTP and/or passkeys on tenant + central admin; enforce for `is_admin` |
| Step-up | Re-auth within N minutes before disbursement batch approve/download and gateway payouts |
| Sessions | Device list + revoke (session metadata) |
| PDPL | Member “download my data” export; account-closure / anonymization request workflow |

#### Phases

1. 2FA enrollment + enforcement.
2. Step-up middleware on money actions (§5.2).
3. Session management UI.
4. Data export / closure.

#### Success criteria

- Admin without 2FA cannot access tenant panel when policy is on.
- Batch download requires fresh step-up even with valid session.

---

### 5.10 Tenant plan & billing (SaaS)

#### Goal

Charge tenants for the product once there is a commercial need.

#### Architecture

| Piece | Approach |
|-------|----------|
| Plans | Member-count tiers + feature flags (gateway, API, OCR) |
| Metering | Nightly job counts active members / API calls |
| Billing | Invoice PDF + payment (can reuse gateway adapter in central context) |
| Enforcement | Grace period → read-only tenant (block posting, allow reports) |

#### Phases

1. Plan model on central DB + feature gate helpers.
2. Metering + admin (central) invoice UI.
3. Grace / read-only enforcement in tenancy middleware.
4. Self-serve upgrade later.

#### Success criteria

- Feature flags hide unpaid modules without breaking ledger integrity.
- Read-only mode blocks money mutations, not login or exports.

**Defer** until at least one paying external tenant exists.

---

## 6. Shared constraints

Apply to every feature above:

1. **Accounting** — follow master/member cash & fund mirror rules; bank clearance is a separate step; prefer shared `AccountingService` helpers.
2. **Filament UX** — icon-only page headers; table standards (filters, group-by, bulk toolbar); confirmation modals via app chrome; bilingual strings (`lang/ar.json` / `__()` where required).
3. **Testing** — Pest feature/unit coverage for posting paths, permissions, and idempotency; no long-lived verification scripts.
4. **Tenancy** — all money and PII scoped to current tenant; webhooks/API must not leak across tenants.
5. **Observability** — notification/delivery logs pattern; structured audit for approvals and file downloads.

---

## 7. Out of scope

- Redesigning the tenant home dashboard product surface (separate from ops list overviews).
- Replacing double-entry with a simplified single-ledger mode.
- Full open-banking AISP/PISP (Lean etc.) before gateway + bulk files prove value.
- ML risk models before rules-based scores ship and accumulate labeled outcomes.

---

## Revision history

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | Sep 2026 | Initial recommendations + implementation plans |
