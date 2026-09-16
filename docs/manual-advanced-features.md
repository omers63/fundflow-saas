# FundFlow Advanced Features — User Manual

**Audience:** Fund administrators, members, and integrators  
**Covers:** Payment gateway, bulk disbursement, governance, profit distribution, risk, OCR, API/webhooks, savings goals, security/PDPL, and SaaS plan gates  
**Related:** [MANUALS.md](MANUALS.md) · [manual-administrator.md](manual-administrator.md) · [manual-member-portal.md](manual-member-portal.md) · [advanced-feature-recommendations.md](advanced-feature-recommendations.md) · [openapi-tenant-v1.yaml](openapi-tenant-v1.yaml)

---

## Table of contents

1. [What these features add](#1-what-these-features-add)
2. [For members](#2-for-members)
3. [For fund administrators](#3-for-fund-administrators)
4. [For integrators (API & webhooks)](#4-for-integrators-api--webhooks)
5. [Security & privacy (PDPL)](#5-security--privacy-pdpl)
6. [Plan features & billing mode](#6-plan-features--billing-mode)
7. [Operational checklists](#7-operational-checklists)
8. [Still out of product scope](#8-still-out-of-product-scope)

---

## 1. What these features add

| Feature | Who uses it | What it does |
|---------|-------------|--------------|
| **Pay now (gateway)** | Members / admins | Pay exact dues in-portal; webhook posts cash with master mirror |
| **Bulk disbursement** | Admins | Dual-control bank file for outbound payouts; clear later without re-ledger |
| **Meetings & motions** | Admins / members | Propose, vote, lock decisions; minutes PDF |
| **Profit distribution** | Admins | Allocate investment returns to member fund shares |
| **Risk scoring** | Admins | Explainable risk bands on members and loan approval |
| **Receipt OCR match** | Admins | Suggest / auto-accept deposits when receipt, bank, SMS agree |
| **API & webhooks** | Integrators | Read tenant data; receive signed event callbacks |
| **Savings goals** | Members | Track fund-balance progress; loan-readiness summary |
| **Security** | Admins | TOTP 2FA, sessions, step-up for batch download |
| **Privacy** | Members / admins | Download my data; closure / anonymization workflow |

Core ledgers, contributions, loans, and bank clearing remain as in the main [fundflow-user-guide.md](fundflow-user-guide.md).

---

## 2. For members

### 2.1 Pay now

1. Sign in to the member portal (`/member`).
2. Where a due amount is shown (contribution, EMI, fee, deposit), use **Pay now** when available.
3. Complete payment with the fund’s gateway (Fake in test; Moyasar / HyperPay in production).
4. On success, cash is credited automatically. You do **not** need to upload a transfer receipt for that payment.

**Notes**

- Amount is locked to what you owe.
- If you are blocked for delinquency or portal maintenance, Pay now may be hidden.

### 2.2 Vote on motions

1. Open **Motions** (or the voting page in the member portal).
2. Cast **Yes / No / Abstain** while voting is open.
3. Results lock when admins close the motion; published minutes are authoritative.

### 2.3 Savings goals & loan readiness

1. Open **Savings goals** or the dashboard card.
2. Create a goal (title, target amount, optional target date). Creating a goal **does not move money**.
3. Progress uses your **live fund balance**.
4. The **Loan readiness** card summarizes whether current rules allow you to apply, with a link to the loan calculator.
5. If you fall behind a target date, you may receive a weekly nudge notification.

### 2.4 Privacy & data (PDPL)

1. Open **Privacy & data**.
2. **Download my data** — JSON export of profile, balances, contributions, and loans.
3. **Request account closure** — sends a request to admins. After approval, personal fields are anonymized; financial history remains for the fund’s books.

---

## 3. For fund administrators

Admin URL: `https://<fund-domain>/admin`

### 3.1 Gateway payments

- Sidebar: **Gateway payments** (when the plan includes the gateway feature).
- Review paid / failed intents; exceptions when webhook amounts do not match owed dues.
- Configure the driver in tenant settings / environment (`fake`, `moyasar`, `hyperpay`).

### 3.2 Bulk disbursement batches

1. Open the **Disbursement batches** workspace.
2. Build a draft from eligible outbound payments (valid IBAN required).
3. Submit for approval — **a different admin** must approve.
4. Confirm password (**step-up**) before approve / download when prompted.
5. Download the bank file (Al Rajhi CSV or pain.001 stub). Download is audited (file hash).
6. After the bank pays, clear statement lines against batch items — **no second ledger debit**.

### 3.3 Meetings, motions, minutes

1. **Meetings & motions** — create a meeting + motion.
2. Open voting; members vote; **Close & resolve** when ready.
3. **Publish minutes** (all open motions must be closed first).
4. **Minutes PDF** — download after minutes are published.

Protected settings and large expenses / distributions may require an **approved motion** (see Governance settings on that page).

### 3.4 Profit distribution

1. Open **Profit distributions**.
2. Create a draft for a period and amount (allocation by fund-balance weight).
3. Approve → Post (credits member fund with master fund mirror).
4. Reverse if needed.
5. **Fiscal year close cannot start** while a draft or approved (unposted) distribution exists.

### 3.5 Risk scoring

- Members list: **Risk** column and filter.
- Loan approval: risk badge; optional block when band is critical (tenant risk settings).
- Weekly digest: `risk:send-watchlist-digest` (scheduled).

### 3.6 Receipt OCR & three-way match

- OCR fields appear on deposit / fund posting review when a receipt is present (Fake driver by default).
- Auto-accept is **off** unless `deposit_ocr.auto_accept_enabled` is turned on.
- Match requires amount agreement within tolerance across receipt, bank line, and SMS when configured.

### 3.7 API & webhooks

1. **API & webhooks** (System) — create a read token (copy once) and webhook endpoints.
2. Delivery log shows pending / delivered / failed HMAC deliveries.
3. Events include: `contribution.posted`, `loan.approved`, `recon.exception.raised`, `gateway.payment.paid`.

### 3.8 Security

1. **Security** — enable TOTP: add the secret to an authenticator app, confirm with a 6-digit code, store recovery codes offline.
2. Optionally **require 2FA for all admins**.
3. Review **Active sessions**; revoke stolen devices; “revoke other sessions” ends everything except this browser.
4. Disbursement approve/download still requires **step-up password** within the TTL window.

### 3.9 Privacy requests

1. **Privacy requests** — review member closure requests.
2. **Approve & anonymize** scrubs name/email/phone; sets member withdrawn. Ledgers stay intact.
3. Reject with notes when appropriate.

---

## 4. For integrators (API & webhooks)

### 4.1 Authentication

- Base URL: `https://<tenant-domain>/api/v1`
- Header: `Authorization: Bearer <token>`
- Tokens are **tenant-scoped** (domain + hashed token). They cannot access another fund.
- Abilities: `members:read`, `balances:read`, `contributions:read`, `loans:read`, or `*`.

### 4.2 Read endpoints

| Method | Path | Ability |
|--------|------|---------|
| GET | `/members`, `/members/{id}` | `members:read` |
| GET | `/balances`, `/balances/{member}` | `balances:read` |
| GET | `/contributions`, `/contributions/{id}` | `contributions:read` |
| GET | `/loans`, `/loans/{id}` | `loans:read` |
| GET | `/statements`, `/statements/{id}` | `balances:read` |

Full schema: [openapi-tenant-v1.yaml](openapi-tenant-v1.yaml).

### 4.3 Webhook verification

Each delivery includes:

- `X-FundFlow-Event`
- `X-FundFlow-Signature` — `HMAC-SHA256(raw_body, endpoint_secret)` hex digest
- `X-FundFlow-Delivery`

Verify with a constant-time compare. Failed deliveries remain visible in the admin delivery log and retry with backoff.

---

## 5. Security & privacy (PDPL)

| Control | Behavior |
|---------|----------|
| TOTP 2FA | Challenge after password when enabled |
| Enforce policy | Admins without 2FA are sent to Security setup |
| Step-up | Fresh password confirm for batch approve/download |
| Sessions | List / revoke from Security page |
| Data export | Member self-service JSON |
| Closure | Admin-approved anonymization |

Passkeys and central-admin 2FA are not in this release.

---

## 6. Plan features & billing mode

Central **plans** may list features in `data.features`: `gateway`, `api`, `webhooks`, `ocr`.

- Missing features hide related admin UI and reject API/webhook use.
- If a subscription expires: **grace** (still writable) then **read-only** (pool-mirror money posts blocked; login and reports still work).
- Metering command: `billing:meter-usage` (active members vs plan max).

Self-serve invoices and fake checkout are available on the central **SaaS billing** page; connect a real PSP when commercial rollout requires card collection.

---

## 7. Operational checklists

### Weekly (admin)

- [ ] Review gateway exceptions and failed webhooks  
- [ ] Review risk watch-list / digest  
- [ ] Close open motions and publish minutes when meetings finish  
- [ ] Confirm no draft/approved profit distributions before fiscal close  

### Monthly (admin)

- [ ] Run / post profit distribution if returns are due  
- [ ] Spot-check OCR auto-accept (if enabled) for false accepts  
- [ ] Rotate API tokens if staff left  

### Member onboarding tip

- Encourage **Pay now** for dues and a **savings goal** so fund balance growth is visible next to loan readiness.

---

## 8. Deferred finishers (now shipped)

| Area | How to use |
|------|------------|
| **SADAD** | Admin: enable SADAD in gateway settings (`SadadSettings` — biller id, merchant code, registration status). Import settlement CSV via SADAD payment file import; paid rows post like other gateway webhooks. |
| **SARIE ack** | After generating a disbursement batch file, use **Import ack** on Disbursement batches with CSV (`item_id,status,reference,reason`) or pain.002-lite XML. Batch moves to **Acknowledged**. |
| **Proxy voting** | Grant a proxy on a motion (`MotionService::grantProxy`); proxy member casts for the grantor via `castProxyVote`. Votes store `cast_by_member_id` / `proxy_for_member_id`. |
| **Cloud OCR** | Set `OCR_DRIVER=cloud` and `ocr.cloud.endpoint` / `api_key`. `CloudReceiptOcrDriver` POSTs the receipt and maps amount/date/IBAN/reference/confidence. |
| **ML risk** | Enable `risk.ml_enabled` (and min labels / blend weight). Label outcomes via `MlRiskScoreService::recordOutcome`; scores blend rules + empirical default rate when enough labels exist. |
| **Passkeys** | Admin Security → register software passkey (WebAuthn-lite). Assertion uses credential + challenge; `PASSKEYS_ALLOW_SOFT_VERIFY` enables test/dev soft signatures. |
| **SaaS billing** | Central **SaaS billing** page: create invoice for a tenant/plan, then fake checkout (or POST `/admin/saas-checkout/{invoice}`) to activate/extend subscription. |
| **Write API** | Bearer token abilities: `payments:write` → `POST /api/v1/gateway-payments`; `votes:write` → `POST /api/v1/motions/{id}/votes`; `goals:write` → `POST /api/v1/savings-goals`; `disbursements:write` → `POST /api/v1/disbursement-batches/{id}/ack`. Dual-control UI still required for batch approve/download. |

Remaining commercial hardening (live SADAD network, production WebAuthn attestation, real PSP checkout, certified ML models) is ops configuration, not product gaps.

---

## Revision

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | Sep 2026 | Initial advanced-features user manual (Phases A–E + gap close) |
| 1.1 | Sep 2026 | Deferred finishers documented as shipped |
| 1.2 | Sep 2026 | Arabic UI strings for finishers; SaaS checkout note corrected |
