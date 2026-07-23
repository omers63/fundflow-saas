# FundFlow Member Portal — Product Specification

| Field | Value |
|-------|-------|
| **Version** | 1.0 |
| **Status** | Draft — pending implementation sign-off |
| **Panel** | Filament `member` at `/member` |
| **Related** | [Analysis & plan](member-portal-redesign-plan.md) · [Implementation plan](member-portal-implementation-plan.md) · [Prototype](Claude/member-portal-prototype.html) |

---

## 1. Purpose & scope

### 1.1 Purpose

Define **what** the redesigned member portal must deliver: information architecture, member-facing behaviour, bilingual rules, communications, documents, and simple analytics — without prescribing implementation details (see [implementation plan](member-portal-implementation-plan.md)).

### 1.2 In scope

- Member panel UI/UX redesign (dashboard, navigation, pages, widgets)
- Consolidation of finance, loan, history, self-service, help, and settings surfaces
- Bilingual Arabic/English with RTL, Western digits, official Saudi Riyal sign (U+20C1) in Arabic
- All member-facing outputs: in-app notifications, email, SMS, WhatsApp, PDFs, CSV exports
- Value-add: communications center, deposit bank instructions, document downloads, dashboard insights
- Preservation of existing business capabilities: household, impersonation, guarantor loans, eligibility overrides, PWA hooks

### 1.3 Out of scope

- Tenant admin panel redesign
- Custom report builder or BI dashboards
- Announcement CMS / machine translation of message bodies
- Native mobile app (PWA remains)
- Changes to core accounting, loan posting, or collection business rules
- Admin broadcast bilingual fields (v1: free-text; documented expectation only)

### 1.4 Success definition

Members can answer within one dashboard view:

1. **What needs my attention now?**
2. **What are my balances and obligations?**
3. **What can I do next?**

---

## 2. Stakeholders & users

| Role | Description |
|------|-------------|
| **Member (borrower)** | Primary user; linked `User` with `member` profile |
| **Household parent** | Member with dependents; may impersonate dependent portal |
| **Guarantor** | Member named on another member's loan; read-only exposure views |
| **Fund administrator** | Uses tenant panel; sends messages, reviews deposits, not a portal user |

### 2.1 Access rules (unchanged)

| User type | Member portal |
|-----------|---------------|
| Linked member, active portal status | Yes |
| Admin (`is_admin`) | No |
| Member in `PORTAL_BLOCKED_STATUSES` | No (logout on access) |
| Orphan user | No |

Authentication: `tenant` guard, `/member/login`, household profile picker where applicable.

---

## 3. Glossary

| Term | Meaning |
|------|---------|
| **Overview** | Default dashboard; command center |
| **Cash account** | Member pool cash balance; deposits and debits |
| **Fund account** | Member fund balance; contributions and loan fund legs |
| **Open period** | Current contribution collection cycle |
| **EMI** | Loan installment collected per cycle |
| **Reserved EMI** | Next installment amount treated as unavailable for cash-out |
| **Threshold** | Loan repayment target (master portion + settlement %) |
| **Communications center** | Help page with Messages (DMs), Requests, Alerts (system/announcement history), FAQ |
| **Western digits** | `0`–`9` Latin numerals in all locales |
| **Saudi Riyal sign** | Unicode U+20C1 (⃁), official SAMA symbol in Arabic UI |

---

## 4. Design principles

| # | Principle |
|---|-----------|
| P1 | **Dashboard = command center** — most actions start from Overview |
| P2 | **Sidebar = wayfinding** — ~9 destinations, not 15 |
| P3 | **Progressive disclosure** — insights/household/guarantor collapsed by default |
| P4 | **One notice at a time** — priority-ranked contextual banner |
| P5 | **No duplicate heroes** — list pages do not repeat dashboard KPI blocks |
| P6 | **Mobile first** — critical actions without horizontal scroll |
| P7 | **Bilingual by default** — en/ar on every touchpoint, same PR as UI |
| P8 | **Filament stays** — redesign is presentation + IA, not a new SPA |

---

## 5. Information architecture

### 5.1 Navigation structure

```
Profile block (avatar, member #, since, status chip)
├── Overview
├── My accounts
│   ├── Cash account
│   └── Fund account
├── Loans
│   ├── My loans [active count badge]
│   ├── Request a loan
│   └── Guaranteed loans [conditional]
├── History
│   ├── Contributions
│   └── Activity
├── Self-service
│   ├── Cash out
│   └── Statements
├── Help [unread badge]
│   └── Communications & FAQ
└── Settings
    └── Profile & preferences
```

### 5.2 Navigation migration

| Current item | Target |
|--------------|--------|
| My Deposits | Cash account |
| My Accounts | Split → Cash + Fund |
| My Cash-Outs | Cash out page |
| Loan calculator | Inline in Request a loan |
| Contribution settings | Settings tab |
| Notification preferences | Settings tab |
| Support & requests | Help → Requests tab |
| Messages (ungrouped) | Help → Messages tab |
| Business calendar testing | **Removed** from member nav |
| My dependents | Overview household + Settings (parents) |

### 5.3 URL compatibility

Bookmarked URLs for retired list pages **must redirect** to the new canonical page for at least one release cycle.

---

## 6. Functional requirements — Overview (dashboard)

### FR-DASH-01 Priority notice

The system shall display **at most one** priority notice at the top of Overview, selected by this order:

1. Delinquent or suspended member status
2. EMI due within configurable N days (amount + link to loan)
3. Contribution due or insufficient cash for open period
4. Pending deposit or cash-out awaiting admin action
5. Unread admin message
6. Pending loan eligibility override
7. Informational (e.g. contribution exempt during active loan repayment)

### FR-DASH-02 Balance panels

The system shall show a two-column balance section:

| Cash panel | Fund panel |
|------------|------------|
| Available balance | Fund balance |
| Reserved for next EMI (if applicable) | Monthly contribution |
| Available to withdraw (cash − reserved) | Borrow headroom (fund × multiplier) |
| Actions: Deposit, Cash out, History | Actions: History, Statement |

Fund panel shall use distinct visual treatment (purple gradient per design system).

### FR-DASH-03 Loan or eligibility panel

- **If active loan:** progress to threshold, EMI chips, guarantor, next due, actions Partial settle / Full settle
- **If no active loan:** eligibility summary + Request a loan CTA

### FR-DASH-04 Quick actions

Up to five quick-action rows: Deposit, Request loan (with max eligible), Cash out (with available amount), Download statement, Messages or Guaranteed loans (contextual).

### FR-DASH-05 Recent activity

Last 5–8 ledger events: description, date, credit, debit, type chip; link to full Activity page.

### FR-DASH-06 Expandable sections (collapsed by default)

| Section | Condition |
|---------|-----------|
| My insights | Always available; collapsed on mobile |
| Household | Parent with dependents or separated member |
| Guarantor exposure | Member is guarantor on ≥1 active loan |

### FR-DASH-07 Pending actions

Operational list (not analytics): pending deposit, pending cash-out, pending loan application, unread messages — with deep links.

### FR-DASH-08 Polling

Dashboard data may refresh on a 60s poll interval (existing behaviour preserved unless changed in implementation).

---

## 7. Functional requirements — pages

### 7.1 Cash account (FR-CASH-*)

| ID | Requirement |
|----|-------------|
| FR-CASH-01 | Show balance, pending clearance, reserved EMI, last deposit/debit |
| FR-CASH-02 | Embed deposit request form (amount, date, reference, attachment, comments) |
| FR-CASH-03 | Show **fund bank transfer instructions** (bank name, IBAN, member reference) from tenant settings |
| FR-CASH-04 | Fallback message when bank details not configured |
| FR-CASH-05 | Full-width cash transaction history table |
| FR-CASH-06 | Deposit history (pending/accepted/rejected) as subsection or integrated list |
| FR-CASH-07 | Optional: copy IBAN to clipboard |

### 7.2 Fund account (FR-FUND-*)

| ID | Requirement |
|----|-------------|
| FR-FUND-01 | Gradient hero with fund balance |
| FR-FUND-02 | Detail grid: monthly contribution, borrow multiplier, total contributed, loan deductions, exemption status, exemption end |
| FR-FUND-03 | Fund ledger history table |

### 7.3 Loans hub (FR-LOAN-*)

| ID | Requirement |
|----|-------------|
| FR-LOAN-01 | Tabbed hub: Active \| History \| Settle \| Apply |
| FR-LOAN-02 | Active: loan cards with installment schedule inline |
| FR-LOAN-03 | History: completed/cancelled loans |
| FR-LOAN-04 | Settle: full/partial settlement (existing business rules) |
| FR-LOAN-05 | Apply: multi-step wizard (eligibility → details → guarantor → review) |
| FR-LOAN-06 | Loan calculator accessible from Apply flow, not standalone nav |
| FR-LOAN-07 | Guaranteed loans: separate nav when count > 0; read-only exposure |

### 7.4 Contributions (FR-CONT-*)

| ID | Requirement |
|----|-------------|
| FR-CONT-01 | Four stat cards: total contributed, this cycle, cycles missed (12 mo), cycles exempt |
| FR-CONT-02 | Contribution history table with status chips |
| FR-CONT-03 | Apply open-period contribution action where permitted |
| FR-CONT-04 | No duplicate dashboard-style insights hero |

### 7.5 Activity (FR-ACT-*)

| ID | Requirement |
|----|-------------|
| FR-ACT-01 | Unified feed: cash + fund + loan events |
| FR-ACT-02 | Filter chips: All, Contributions, EMI, Deposits, Late fees, Loan events, Cash outs |
| FR-ACT-03 | Localized transaction descriptions via reference type map |
| FR-ACT-04 | Credit/debit columns with signed amount styling |

### 7.6 Cash out (FR-CO-*)

| ID | Requirement |
|----|-------------|
| FR-CO-01 | Two-column layout: request form \| history |
| FR-CO-02 | Prominent **available balance** = cash − reserved EMI |
| FR-CO-03 | Show payout destination (registered IBAN) when stored |
| FR-CO-04 | Submit via existing `MemberCashOutService` rules |

### 7.7 Statements & documents (FR-DOC-*)

| ID | Requirement |
|----|-------------|
| FR-DOC-01 | List monthly statements with PDF download (existing route) |
| FR-DOC-02 | Download center: activity CSV (date range) |
| FR-DOC-03 | Download center: loan schedule PDF (active loan) |
| FR-DOC-04 | Optional v1.1: contributions CSV export |
| FR-DOC-05 | Optional v1.1: deposit receipt after acceptance |
| FR-DOC-06 | CSV: UTF-8 BOM, translated headers, Western digits |

### 7.8 Help & communications (FR-HELP-*)

| ID | Requirement |
|----|-------------|
| FR-HELP-01 | Single Help nav item with unread badge |
| FR-HELP-02 | Tab: Messages — embed existing message threads |
| FR-HELP-03 | Tab: Requests — support tickets + household member requests |
| FR-HELP-04 | Tab: Alert history — read-only `notification_logs` for current user |
| FR-HELP-05 | FAQ accordion (prototype Q&As in lang files; tenant override later) |
| FR-HELP-06 | Alert history: no resend; paginated; channel + status columns |

### 7.9 Settings (FR-SET-*)

| ID | Requirement |
|----|-------------|
| FR-SET-01 | Tabbed page: Profile, Contributions, Notifications, Security, Payout details |
| FR-SET-02 | Profile: view/edit identity, household context |
| FR-SET-03 | Contributions: monthly amount + dependent allocations |
| FR-SET-04 | Notifications: per-category channel preferences (existing service) |
| FR-SET-05 | Payout details: read-only; "Contact support to update" |
| FR-SET-06 | Security: password change if exposed to members |

### 7.10 Household (FR-HH-*)

| ID | Requirement |
|----|-------------|
| FR-HH-01 | Overview expandable household block for parents |
| FR-HH-02 | Dependent impersonation entry from household block |
| FR-HH-03 | Return-to-parent banner when impersonating (existing) |
| FR-HH-04 | Dependent management not duplicated as top-level nav for all users |

---

## 8. Functional requirements — analytics (simple)

| ID | Requirement |
|----|-------------|
| FR-AN-01 | No separate Analytics navigation item |
| FR-AN-02 | Expandable "My insights" on Overview: 6-month contribution sparkline |
| FR-AN-03 | Stat chips: YTD contributed, YTD repaid, borrow headroom, cycles missed (12 mo) |
| FR-AN-04 | Guarantor one-liner when applicable |
| FR-AN-05 | Hide insights section when member has no meaningful history (empty state) |

---

## 9. Non-functional requirements

### 9.1 Internationalization (NFR-I18N-*)

| ID | Requirement |
|----|-------------|
| NFR-I18N-01 | Locales: `en` (default), `ar` |
| NFR-I18N-02 | Language switch persists to `User.preferred_locale` |
| NFR-I18N-03 | All user-visible strings translatable; keys in `lang/ar.json` same PR as UI |
| NFR-I18N-04 | **Western digits only** in all numeric display (`0`–`9`) |
| NFR-I18N-05 | English currency: `SAR` prefix — e.g. `SAR 3,240.00` |
| NFR-I18N-06 | Arabic currency: U+20C1 (⃁) prefix via `__('SAR')` — e.g. `⃁ 3,240.00` |
| NFR-I18N-07 | Do not use `ر.س` or U+FDFC `﷼` in new work |
| NFR-I18N-08 | RTL layout for `ar`; logical CSS properties in new components |
| NFR-I18N-09 | Async outputs use recipient `preferred_locale`, not sender/cron default |

### 9.2 Performance (NFR-PERF-*)

| ID | Requirement |
|----|-------------|
| NFR-PERF-01 | Dashboard snapshot composable without N+1 on member, accounts, active loan |
| NFR-PERF-02 | Activity feed paginated (default 25 rows) |
| NFR-PERF-03 | CSV export streamed for ranges ≤ 24 months |

### 9.3 Security (NFR-SEC-*)

| ID | Requirement |
|----|-------------|
| NFR-SEC-01 | All queries scoped via `CurrentMember` / `member_id` |
| NFR-SEC-02 | Alert history: only own `notification_logs` rows |
| NFR-SEC-03 | Statement PDF: only own `MonthlyStatement` records |
| NFR-SEC-04 | Activity export: only own transactions |

### 9.4 Accessibility (NFR-A11Y-*)

| ID | Requirement |
|----|-------------|
| NFR-A11Y-01 | Notice banners use semantic colour + text (not colour alone) |
| NFR-A11Y-02 | Focus order correct in RTL |
| NFR-A11Y-03 | Amount fields expose `inputmode="decimal"` where appropriate |

### 9.5 Compatibility (NFR-COMP-*)

| ID | Requirement |
|----|-------------|
| NFR-COMP-01 | Mobile viewports 320px+ usable without horizontal scroll for primary actions |
| NFR-COMP-02 | Saudi Riyal sign font bundled for browsers without U+20C1 glyph |
| NFR-COMP-03 | PWA and Arabic font hooks preserved |

---

## 10. Member-facing outputs specification

### 10.1 Channels

| Channel | Localized at send/render | Western digits | Currency symbol |
|---------|--------------------------|----------------|-----------------|
| Portal UI | Request locale | Yes | `__('SAR')` |
| Database notifications | Recipient locale | Yes | `__('SAR')` |
| Email | Recipient locale | Yes | `__('SAR')` |
| SMS / WhatsApp | Recipient locale | Yes | `__('SAR')` |
| Monthly statement PDF | Request locale | Yes | `__('SAR')` |
| Activity CSV | Request locale | Yes | N/A in cells |
| Loan schedule PDF | Request locale | Yes | `__('SAR')` |
| Direct message UI chrome | Request locale | Yes | — |
| Message body | Author language | — | — |
| Support request UI | Request locale | Yes | — |

### 10.2 Notification categories (member)

Contributions, loan repayment, loan activity, loan alerts, account alerts, statements, allocations, broadcasts (admin free-text).

Preferences respected per `NotificationPreferenceService` and `MemberCommunicationPreference`.

### 10.3 PDF requirements

- HTML `dir="rtl"` when Arabic
- Section headings translated
- Transaction descriptions from `Transaction::memberFacingDescription()` (not raw DB English)
- Arabic-capable font including U+20C1
- Footer disclaimer from tenant `StatementSettings`

---

## 11. Visual design specification (summary)

Reference: [prototype](Claude/member-portal-prototype.html). Full tokens in [implementation plan](member-portal-implementation-plan.md#4-design-system).

| Token | Hex | Use |
|-------|-----|-----|
| Primary | `#534AB7` / `#3C3489` | CTAs, active nav (member panel only) |
| Success | `#1D9E75` | Credits, collected |
| Warning | `#EF9F27` | Due soon, reserved EMI |
| Danger | `#E24B4A` | Debits, arrears |
| Info | `#378ADD` | Informational notices |

| Component | Spec |
|-----------|------|
| Panel | 14px radius, head/body split, optional "Details" link |
| Notice | amber / blue / green / red variants |
| Chip | green, amber, red, blue, purple, gray status pills |
| Quick action | Icon square + title + subtitle row |
| Typography | ~13px base; amounts 22–34px bold |

### 11.1 Filament chrome overrides (Phase 1)

All member panel Filament surfaces (forms, tables, buttons, sidebar, topbar) shall be restyled to match the prototype via **`member-portal-chrome.css`**, scoped under `.fi-panel-member` only. See [implementation plan §4.4](member-portal-implementation-plan.md#44-prototype-fidelity-css-phase-1--required).

| Surface | Prototype fidelity target |
|---------|---------------------------|
| Page background | `#f9fafb` (gray-50) |
| Sidebar nav | Compact 12px items; uppercase group labels; active gray pill |
| Form fields | 8px radius, 12px font, purple focus ring |
| Primary buttons | Purple `#534AB7`, 9px radius |
| Data tables | Compact 12px; pale header row; subtle row hover |
| Tabs / filter chips | Primary underline / filled chip when active |

**Acceptance:** Side-by-side screenshot review vs prototype at 375px and 1280px before Phase 2 merge (forms + tables on any member list page minimum in Phase 1).

Admin tenant panel retains existing Emerald theme.

---

## 12. Acceptance criteria (release)

### 12.1 Overview

- [ ] Single priority notice renders per ranking rules
- [ ] Cash and fund panels show correct balances and actions
- [ ] Active loan panel or eligibility CTA renders correctly
- [ ] Quick actions deep-link to working flows
- [ ] Recent activity matches ledger

### 12.2 Navigation

- [ ] ≤10 sidebar items for typical member (no Business Day Testing)
- [ ] Old deposit URL redirects to Cash account
- [ ] Guaranteed loans hidden when count = 0

### 12.3 Bilingual

- [ ] Full pass in `en` and `ar` on dashboard, cash, loan, help
- [ ] No Arabic-Indic digits in rendered amounts
- [ ] `⃁` visible in Arabic with bundled font
- [ ] RTL layout intact on mobile

### 12.4 Outputs

- [ ] Sample notification renders correct locale in DB + email snapshot test
- [ ] Statement PDF downloads in member locale
- [ ] Activity CSV opens correctly in Excel with Arabic headers

### 12.5 Value-add

- [ ] Alert history shows only member's rows
- [ ] Bank instructions on cash page when configured
- [ ] Insights expandable shows YTD stats
- [ ] FAQ accordion renders in both locales

### 12.6 Prototype visual fidelity (Phase 1+)

- [ ] Member panel page background matches prototype gray-50
- [ ] Form inputs: 8px radius, purple focus, 11px labels
- [ ] Primary buttons purple; outline/gray secondary match prototype
- [ ] Tables: compact header row, 12px body, hover state
- [ ] Sidebar profile block matches prototype layout
- [ ] No visual regressions on tenant admin panel (scoped CSS only)

### 12.7 Regression

- [ ] Deposit submit → admin accept flow unchanged
- [ ] Cash-out, loan apply, contribution apply, impersonation still work
- [ ] Existing Pest member portal tests pass or updated

---

## 13. Open decisions (product)

| ID | Question | Default recommendation |
|----|----------|----------------------|
| OD-01 | Password self-serve in Settings? | Yes if `User` password reset already supported |
| OD-02 | Cash vs Fund: two pages or one resource with tabs? | Two pages |
| OD-03 | Admin broadcast bilingual fields? | Defer; document admin practice |
| OD-04 | Contributions CSV in v1? | Phase 8b optional |
| OD-05 | Deposit receipt PDF in v1? | Phase 8b optional |

---

## 14. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-06-18 | Initial specification from redesign analysis |
