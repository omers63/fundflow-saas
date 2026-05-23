# Fund Management System — UI/UX Specification
## Admin Portal & Member Portal

**Document type:** UI/UX layout, page inventory, and interaction specification
**Covers:** All pages, sub-pages, panels, forms, actions, and navigation for both portals

---

## Table of contents

1. [Design system & shared conventions](#1-design-system--shared-conventions)
2. [Admin portal](#2-admin-portal)
   - 2.1 Shell & navigation
   - 2.2 Dashboard
   - 2.3 Members
   - 2.4 Loan queue
   - 2.5 Collection cycles
   - 2.6 Disbursements
   - 2.7 Reconciliation
   - 2.8 Bank clearing
   - 2.9 Reports
   - 2.10 Migration
   - 2.11 Configuration
   - 2.12 Audit log
3. [Member portal](#3-member-portal)
   - 3.1 Shell & navigation
   - 3.2 Overview
   - 3.3 Cash account
   - 3.4 Fund account
   - 3.5 My loans
   - 3.6 Loan request wizard
   - 3.7 Contribution history
   - 3.8 Transaction history
   - 3.9 Cash out
   - 3.10 Statements
   - 3.11 Settings
   - 3.12 Help & FAQ
4. [Shared components](#4-shared-components)
5. [Access control & role rules](#5-access-control--role-rules)

---

## 1. Design system & shared conventions

### Layout shell

Both portals share the same structural shell:

```
┌────────────────────────────────────────────────────────┐
│  [192px sidebar]  │  [top bar 48px]                    │
│                   │────────────────────────────────────│
│  Logo / profile   │  Content area (scrollable)         │
│  Navigation       │                                    │
│  Footer actions   │                                    │
└────────────────────────────────────────────────────────┘
```

- **Sidebar:** 192px fixed width, white background, left-anchored navigation
- **Top bar:** 48px height, page title left, action buttons right
- **Content area:** fluid width, background tertiary, 16px padding, independent scroll
- **Shell border:** 0.5px tertiary border, large border-radius container

### Typography scale

| Use | Size | Weight |
|---|---|---|
| Page title (top bar) | 14px | 500 |
| Section / panel title | 12–13px | 500 |
| Body / table text | 12px | 400 |
| Labels, captions, metadata | 10–11px | 400–500 |
| Navigation items | 12px | 400 (active: 500) |

### Colour usage

| Colour role | Usage |
|---|---|
| Purple (#534AB7) | Primary actions, active nav, loan/fund accent |
| Green | Success states, collected, cleared, positive amounts |
| Amber | Warning states, partial, overdue, pending |
| Red | Danger, critical, delinquent, negative amounts |
| Blue | Info, standard loan type, bank events |
| Secondary background | Table headers, empty states, summary cells |

### Status chip palette

| Chip | Colour | Used for |
|---|---|---|
| Active / Collected / Cleared | Green | Positive terminal states |
| Overdue / Warning / Partial | Amber | Attention-required states |
| Critical / Delinquent / Danger | Red | Blocking or error states |
| Standard / Info / Bank | Blue | Informational states |
| Migration / Loan / Fund | Purple | Domain-specific states |
| Neutral / Exempt / Scheduled | Gray | Inactive or non-action states |

### Navigation badge conventions

- Red badge → requires immediate action (e.g. critical reconciliation exception)
- Amber badge → requires attention (e.g. migration pending, overdue members)
- No badge → informational count only

### Notice / alert bar conventions

| Type | Colour | When to use |
|---|---|---|
| Red notice | Danger | Blocking condition (batch halted, funds insufficient) |
| Amber notice | Warning | Action recommended (EMI due soon, balance low) |
| Blue notice | Info | Context-setting information, no action required |
| Green notice | Success | Confirmation, all checks passed |

### Button hierarchy

| Level | Style | Use |
|---|---|---|
| Primary | Purple fill, white text | Main confirm/save action per page |
| Secondary | Outlined, muted text | Secondary actions, cancel, back |
| Danger | Red outlined | Destructive actions — reject, suspend, delete |
| Inline action | Borderless small | Table row actions — View, Edit |

---

## 2. Admin portal

### 2.1 Shell & navigation

**Sidebar structure:**

```
[Logo mark + FundAdmin wordmark + "Admin portal" subtitle]

OPERATIONS
  Dashboard
  Members           [amber badge: migration-pending count]
  Loan queue        [red badge: pending approval count]
  Collection cycles
  Disbursements

FINANCE
  Reconciliation    [red badge: open exception count]
  Bank clearing
  Reports

SYSTEM
  Migration
  Configuration
  Audit log

[Footer: admin avatar + name + role + settings icon]
```

**Top bar (persists across all pages):**
- Left: current page title (updates on navigation)
- Centre: active cycle tag (e.g. "Cycle: May 2026") — informational only
- Right: bell icon (notifications), context-sensitive primary action button, Export button

**Context-sensitive top bar actions by page:**

| Page | Primary button | Secondary button |
|---|---|---|
| Dashboard | Run batch | Export |
| Members | Add member | Export |
| Loan queue | Process next | Export |
| Collection cycles | Run debit batch | Export |
| Disbursements | New disbursement | Export |
| Reconciliation | Run batch now | Export |
| Bank clearing | Import statement | Export |
| Reports | Generate | Download all |
| Migration | Batch classify | Export |
| Configuration | Save all | Reset |
| Audit log | Export log | Filter |

---

### 2.2 Dashboard

**Purpose:** Single-screen operational status summary. First screen seen on login.

**Layout:**

```
[4 metric cards — full width row]
[Loan queue panel (60%) | Reconciliation alerts panel (40%)]
[Cycle progress panel (33%) | Member activity panel (33%) | Fund tier usage panel (33%)]
```

**Metric cards (left to right):**

| Card | Value shown | Sub-text |
|---|---|---|
| Total members | Count | Delta vs prior cycle |
| Collected this cycle | Percentage | Members collected / total |
| Active loans | Count | Queue count |
| Recon exceptions | Count | Severity breakdown |

**Loan queue panel:**
- Shows top 4 requests by queue position
- Columns: rank, member name, amount, type chip, Review button
- Emergency requests always appear at top with red chip
- "View all →" link navigates to Loan queue page

**Reconciliation alerts panel:**
- Shows top 3 open exceptions with coloured dot (red/amber/blue by severity)
- Each row: exception type, short description, time elapsed
- "Open queue →" link navigates to Reconciliation page
- Auto-resolved count shown in footer note

**Cycle progress panel:**
- Four horizontal progress bars: Collected, Partially due, Overdue, Exempt
- Percentages labelled right of each bar

**Member activity panel:**
- Last 4–5 member events (contribution, overdue, migration, loan, EMI)
- Each row: avatar initials, name, event description, status chip

**Fund tier usage panel:**
- Four horizontal bars: Tier A, Tier B, Tier C, Tier E
- Percentage utilisation (committed vs available)
- Warning note if any tier approaches capacity

---

### 2.3 Members

**Purpose:** Full member directory with search, filter, and access to individual member records.

**Sub-pages:**
1. Members list
2. Member detail (with 5 tabs)
3. New member form

#### Members list

**Controls:**
- Search bar: name, ID, or status
- Filter chips: All | Active | Migration pending | Delinquent | Suspended
- "Add member" button (top bar)

**Table columns:** Member ID, Name, Status chip, Cash balance, Fund balance, Active loan count, Actions (View + Edit)

#### Member detail — 5 tabs

**Tab 1 — Profile**
- Left panel: Full name, Member ID, Join date, Status, Phone, Email, Tenure, Guarantor-for
- Action buttons: Suspend (danger), Admin override, Edit profile
- Right panel: Activity timeline (last 5 events with timestamps)

**Tab 2 — Accounts**
- Left panel: Cash account — Balance, Pending clearance, Last deposit, Last debit
- Action: Post manual entry button
- Right panel: Fund account — Balance, Loan multiplier, Opening balance, Total contributions

**Tab 3 — Loans**
- Active loan summary card: amount, EMI, threshold, repaid, tier, guarantor, grace period, next EMI
- Action buttons: View schedule, Partial settle, Full settle

**Tab 4 — Cycle history**
- Table: Cycle, Due amount, Status chip, Collected, Fee applied
- Shows exempt cycles clearly

**Tab 5 — Transactions**
- Table: Date, Description, Amount (colour-coded), Transaction type (DR/CR chip)

#### New member form

**Fields:**
- Full name, National ID / Iqama
- Phone, Email
- Join date, Migration cutoff date (optional — for migrated members only)
- Cash account opening balance (SAR), Fund account opening balance (SAR)

**Actions:** Cancel, Save member

---

### 2.4 Loan queue

**Purpose:** Process loan requests in FIFO order; emergency requests at front.

**Filter chips:** All | Emergency | Standard | Pending approval | Partially disbursed

**Table columns:** Queue rank, Member name, Requested amount, Fund tier, Fund available (colour-coded — green if sufficient, red if insufficient), Type chip, Actions (Approve + Reject / Partial + Reject)

#### Loan review sub-page

Accessed via "Review" or "Approve" from queue table.

**Left panel — Request details:**
- Requested amount, Type, Fund tier, Tier available balance
- Member portion, Master portion, Repayment threshold, Guarantor, Grace period elected, EMI tier

**Right panel — Eligibility checks:**
- Six eligibility items, each with pass/fail icon and detail value
- Any failed gate shows red notice with override option

**Admin decision form:**
- Disbursement amount (pre-filled, editable for partial)
- Decision selector: full approval / partial / reject
- Admin notes textarea
- Actions: Cancel, Reject request (danger), Confirm disbursement (primary)

---

### 2.5 Collection cycles

**Purpose:** Monitor and manage the current and historical collection cycles.

**Filter chips:** Cycle month selector | Current | Past cycles

**Metric cards:** Due (member count), Collected (count + SAR), Overdue (count + SAR), Exempt (count)

**Member collection status table:**
- Columns: Member name, Due amount, Status chip, Collected amount, Days late, Fee tier chip, Actions
- "Post manual" button appears for overdue members
- "Run debit batch" primary action in top bar triggers the auto-debit pass

---

### 2.6 Disbursements

**Purpose:** Track and post loan disbursement tranches.

**Table columns:** Member + Loan ID, Approved amount, Disbursed to date, Remaining, Status chip, Actions

**Statuses:** Active (green) | Partially disbursed (amber) | Pending first disbursement (blue)

**Actions per row:** View loan (for active) | Disburse (for partial or pending)

#### Disburse tranche sub-page

**Info notice:** Loan activates and schedule builds only after full amount disbursed.

**Form fields:** Loan ID (read-only), Member (read-only), Approved amount (read-only), Previously disbursed (read-only), Tranche amount, Fund tier (read-only), Notes

**Actions:** Cancel, Post disbursement (primary)

---

### 2.7 Reconciliation

**Purpose:** Monitor reconciliation batch results, resolve exceptions.

**Metric cards:** Open exceptions, Auto-resolved (last batch), Last batch run time, Next batch time

**Critical notice bar:** Shown when a CRITICAL exception is open (batch halted).

**Exception queue table:**
- Columns: Exception type, Domain, Severity chip, Amount delta, SLA deadline (colour-coded), Actions (Resolve)
- Sorted by severity descending

**Auto-resolved table:**
- Columns: Exception type, Domain, Resolution type (Auto chip), Resolved timestamp

#### Resolve exception sub-page

**Left panel — Exception details:**
- Exception ID, Domain, Severity, Delta amount, Raised at, Auto-resolve attempted, Assertion that failed, Batch status

**Right panel — Resolution action:**
- Action selector: Post rounding adj. / Reverse transaction / Reclassify / Write-off (disabled for CRITICAL) / Escalate / Override and accept
- Correction amount
- Reason code selector
- Notes (mandatory for CRITICAL severity)
- Actions: Cancel, Post resolution & resume batch

---

### 2.8 Bank clearing

**Purpose:** Match bank import lines to system cash account entries.

**Metric cards:** Imported today, Auto-matched, Unmatched (requiring action), Stale pending

**Unmatched table:**
- Columns: Date, Bank reference, Amount, Exception type chip, Actions (Match manually / Adjust & clear)

**Matched table:**
- Columns: Bank line description, Matched cash entry, Amount, Cleared chip

**Top bar action:** Import statement — triggers bank statement file upload

---

### 2.9 Reports

**Layout:** Two panels side by side.

**Left panel — Standard reports (4 report cards):**
1. Monthly collection report (per-member collection status, fees, exemptions)
2. Loan portfolio report (active, repaid, delinquent, all tiers)
3. Reconciliation summary (exceptions, resolutions, auto-resolve log)
4. Fund tier utilisation (availability, committed, disbursed per tier)
Each card has a Download button.

**Right panel — Custom report builder:**
- Report type selector
- Date range (from / to)
- Member filter (optional)
- Format selector: PDF / Excel / CSV
- Generate report button

---

### 2.10 Migration

**Purpose:** Manage migrated members — classify stubs, assign resolution methods, grant clearance.

**Filter chips:** All | Unresolved stubs | Pending clearance | Cleared

**Table columns:** Member name, Join date, Cutoff date, Total stubs, Unresolved count, Status chip, Actions (View / Resolve / Classify)

**Batch classify button:** Opens a form to apply a classification and resolution method to a date range for one or multiple members.

**Member stub resolution sub-page:**
- Shows all stubs in a table with classification dropdowns per row or range
- Resolution method selector per BACKDATED_DUE group
- Clearance eligibility checklist
- Grant clearance button (disabled until all mandatory conditions met)

---

### 2.11 Configuration

**Purpose:** Manage all system parameters. Organised into 5 tabs.

#### Tab 1 — Collection

Fields: Collection window days, Late fee model (replacement/cumulative), Tier 1/2/3 day thresholds, Tier 1/2/3 fee amounts.

#### Tab 2 — Loans

Fields: Minimum membership years, Minimum fund balance, Borrow multiplier, Max active loans per member, Settlement threshold %, Grace period options.

#### Tab 3 — Fund tiers

Two sections:
- EMI tier table: editable rows for Tier 1/2/3 — min amount, max amount, EMI per cycle
- Fund tier allocations: Tier A/B/C/E percentage inputs

#### Tab 4 — Reconciliation

Fields: Auto-resolve tolerance (SAR), Timing diff. defer hours, Bank match date range (± days), Stale pending threshold days, Unbanked cash alert days.

#### Tab 5 — Guarantor rules

Fields: Missed EMIs before warning (X), Missed EMIs before transfer (Y).

---

### 2.12 Audit log

**Purpose:** Immutable record of all admin actions, system events, overrides, and reconciliation entries.

**Filter chips:** All events | Admin actions | Overrides | Recon events | Loan events

**Table columns:** Timestamp, Event description, Actor (Admin / System), Entity (member ID, loan ID, etc.), Event tag chip

**Event tag chips:**
- Recon (blue): reconciliation events
- Auto (green): system auto-resolution
- Loan (purple): loan lifecycle events
- Migration (purple): migration events
- Override (amber): admin override actions

**Export:** Full CSV or PDF export via top bar button.

---

## 3. Member portal

### 3.1 Shell & navigation

**Sidebar structure:**

```
[Member avatar (initials) + Full name + Member ID + since date + Status pill]

  Overview

MY ACCOUNTS
  Cash account
  Fund account

LOANS
  My loans         [amber badge: "1 active"]
  Request a loan

HISTORY
  Contributions
  Transactions

SELF-SERVICE
  Cash out
  Statements
  Settings
  Help & FAQ

[Footer: Sign out]
```

**Top bar:**
- Left: current page title
- Right: notification bell, context-sensitive action button

**Context-sensitive top bar by page:**

| Page | Button |
|---|---|
| Overview | Statement |
| Cash account | Download |
| Fund account | Download |
| My loans | Download schedule |
| Loan request | Save draft |
| Contributions | Export |
| Transactions | Export |
| Cash out | History |
| Statements | Download all |
| Settings | Save all |
| Help | Contact support |

---

### 3.2 Overview

**Purpose:** At-a-glance summary of member's full financial position plus the most time-sensitive alert.

**Layout:**

```
[Amber notice bar — next EMI due date and amount, if applicable]
[Cash account card (50%) | Fund account card (50%)]
[Active loan panel (55%) | Quick actions panel (45%)]
[Recent transactions table — full width]
```

**Cash account card:**
- Large balance display
- "Available balance" sub-label
- Three action buttons: Deposit, Cash out, History
- Navigates to respective pages on click

**Fund account card (purple tint):**
- Large balance display (purple)
- "Accumulated contributions · loan cap: SAR X" sub-label
- Two action buttons: History, Statement

**Active loan panel:**
- Loan amount, approval date, tier, EMI
- Progress bar: repaid % of 18 EMIs
- Repaid / remaining amounts under bar
- Three detail chips: EMIs completed, Repayment threshold, Guarantor
- Next EMI date and amount in summary cell
- Partial settle + Full settle buttons

**Quick actions (4 cards):**
1. Deposit funds → Cash account
2. Request a loan → Loan request wizard (shows max eligible)
3. Cash out → Cash out page (shows available)
4. Settle loan → My loans / settle tab

**Recent transactions table:**
- 4–5 most recent entries
- Columns: Description, Date, Amount (colour-coded), Type chip (DR/CR)
- "All →" link to transactions page

---

### 3.3 Cash account

**Purpose:** Full view of cash balance, deposit facility, and full cash transaction history.

**Layout:** Two panels top row, one full-width history panel below.

**Balance panel (left):**
- Large balance figure
- Detail grid: Pending clearance, Pending debit (next EMI reserved), Last deposit, Last debit

**Deposit panel (right):**
- Blue info notice explaining direct vs bank transfer timing
- Deposit method selector: Bank transfer (IBAN) / Direct cash deposit
- Amount field
- Reference / notes field
- Confirm deposit button

**Cash account history table:**
- Columns: Description, Date, Amount (colour-coded), Running balance, Status chip

---

### 3.4 Fund account

**Purpose:** Fund balance detail, contribution rate, borrow capacity, and fund history.

**Balance panel (purple tint — full width):**
- Large purple balance figure
- Detail grid: Monthly contribution amount, Borrow multiplier and max loan, Total contributions, Loan deductions (member portion), Contribution status (Active or Exempt), Exemption end condition

**Fund account history table:**
- Columns: Description, Cycle month, Amount (colour-coded), Running balance

---

### 3.5 My loans

**Purpose:** View active loan schedule, loan history, and settle loans.

**Three tabs:** Active loans | Loan history | Settle loan

#### Active loans tab

- Full loan detail block: amount, approval date, tier, EMI
- Progress bar: repaid % toward threshold
- Repaid SAR / remaining SAR to threshold
- Six detail chips: EMIs completed, Threshold, Guarantor, Grace period, Fund tier, Next EMI
- Full repayment schedule table: Cycle, EMI due, Status chip, Collected amount
- "Settle loan" button navigates to Settle tab

#### Loan history tab

- Table: Loan ID, Amount, Status chip, Approved date, Repaid date
- Shows all historical and active loans

#### Settle loan tab

**Settlement type selector (two cards):**

**Full settlement card:**
- Shows exact outstanding threshold balance
- Source selector (cash account with current balance)
- Amber warning if balance insufficient (with "Deposit funds first" button)
- Confirm full settlement button

**Partial settlement card:**
- Partial amount input (min 1 EMI enforced)
- Schedule treatment selector (two sub-cards):
  - Roll-up (compress): covered cycles marked settled, schedule shortens
  - Skip cycles: covered cycles skipped, gap inserted, total length preserved
- Confirm partial settlement button

---

### 3.6 Loan request wizard

**Purpose:** Step-by-step loan application with eligibility pre-check, details, guarantor, and review.

**Step indicator (4 steps, horizontal):** Eligibility → Loan details → Guarantor → Review & submit

Active step highlighted in purple. Prior steps remain filled (indicating completion).

#### Step 1 — Eligibility

- Six check items with pass/warn/fail icons:
  1. Membership tenure
  2. Fund account balance
  3. No delinquency
  4. Active loan count (within limit)
  5. Borrow limit remaining
  6. (Any failed gate shows red with admin override note)
- Continue button (disabled if any hard fail)

#### Step 2 — Loan details

- Loan type selector: Standard / Emergency
- Requested amount (with max eligible shown)
- EMI tier (auto-filled and read-only based on amount)
- Grace period selector: 0 / 1 / 2 cycles
- Purpose textarea (optional)
- Live loan summary box:
  - Requested amount, Member portion, Master portion, Repayment threshold, EMI per cycle, Estimated cycles
- Back / Continue buttons

#### Step 3 — Guarantor

- Guarantor member ID input
- Guarantor name (auto-filled on ID entry, read-only)
- Amber notice: liability explanation (what guarantor is liable for, and the trigger conditions)
- Back / Continue buttons

#### Step 4 — Review & submit

- Green success notice: all checks passed, queue position note
- Full loan summary block (read-only)
- Back / Submit loan request buttons
- On submit → returns to Overview with amber notice confirming queue placement

---

### 3.7 Contribution history

**Purpose:** Full contribution cycle record including exempt cycles, late fees, and totals.

**Metric cards (4):**
- Total contributed (SAR, since join)
- This cycle status chip + amount
- Cycles missed (count)
- Cycles exempt (count)

**Contribution history table:**
- Columns: Cycle month, Status chip, Amount due, Amount collected, Late fee applied, Days late

---

### 3.8 Transaction history

**Purpose:** Complete filterable transaction ledger across all account activities.

**Filter chips:** All | Contributions | EMI | Deposits | Late fees | Loan events

**Transaction table:**
- Columns: Description, Date, Amount (green for CR, red for DR), Account (Cash/Fund), Type chip (DR/CR)
- Filtered in real-time by chip selection

---

### 3.9 Cash out

**Purpose:** Request a withdrawal from the cash account to registered bank account.

**Layout:** Two panels side by side.

**Cash out request panel (left):**
- Blue info notice: explains that cash out draws from cash account, not fund account; pending debits are reserved
- Detail grid: Cash account balance, Reserved for next EMI, Available to withdraw, Processing time
- Withdrawal amount field (max = available enforced)
- Destination selector: registered IBAN or other
- Notes field
- Submit cash out request button

**Cash out history panel (right):**
- Table: Date, Amount, Status chip

---

### 3.10 Statements

**Purpose:** Self-service statement generation and download of prior statements.

**Layout:** Two panels side by side.

**Statement generator (left):**
- Statement type selector: Combined / Cash only / Fund only / Loan #ID / Contribution history
- Period from / to date pickers
- Format selector: PDF / Excel / CSV
- Download statement button

**Recent statements (right):**
- Table: Statement name, Period covered, Download PDF button per row

---

### 3.11 Settings

**Four tabs:** Profile | Notifications | Bank details | Security

#### Profile tab

- Full name, Member ID (read-only), Phone, Email
- Save changes button

#### Notifications tab

- Table of notification events with delivery selector per event:
  - EMI due reminder, Contribution debit, Late fee applied, Loan status updates, Deposit received
- Options per event: SMS + Email / Email only / SMS only / Off
- Save preferences button

#### Bank details tab

- Read-only display: Bank name, Account name, IBAN, Verified chip
- Edit button opens inline edit form

#### Security tab

- Current password, New password, Confirm new password
- Update password button

---

### 3.12 Help & FAQ

**Purpose:** Self-service answers to the most common member questions.

**Format:** Expandable accordion items (click to expand/collapse).

**FAQ items:**

1. When is my contribution collected?
2. Why am I exempt from contributions?
3. How is my loan repayment threshold calculated?
4. What happens if I miss EMI payments?
5. How do I partially settle my loan?
6. How do I add funds to my cash account?
7. What is my guarantor responsible for?
8. How do I request a cash out?

**Contact support button** in top bar opens a support request form or contact details.

---

## 4. Shared components

### Notice / alert bar

Used across both portals. Four variants (red, amber, blue, green). Always displayed above the first content panel on the page. Icon left, text right. Dismissible for info/success variants; persistent for warning/danger.

### Status chip

Inline, compact, read-only. Colours defined in Section 1. Used in all tables and detail views.

### Detail grid

Two-column key-value layout. Label in tertiary colour (10px), value in primary (12px, 500 weight). Used in all detail panels. Bottom border on each row; last two items have no border.

### Progress bar row

Label (90px fixed), bar (flex), percentage (28px fixed right-aligned). Bar height 4–6px, rounded. Colour-coded by value range or domain.

### Timeline

Vertical list with dot-and-line connector. Filled dot = recent/complete event. Open dot = older. Event title (12px, 500) + sub-text (11px, tertiary). Used in member detail profile tab and loan history.

### Tabbed sub-page

Horizontal tab bar at top of panel content area. Active tab: primary colour bottom border (2px), 500 weight text. Each tab toggles a sub-page block below. Used in: Member detail, My loans, Loan request wizard (step indicator variant), Configuration, Settings.

### Data table

Fixed table layout. Header row: 10px uppercase, 500 weight, secondary background. Body rows: 12px, 7px vertical padding, bottom border. Last row: no border. Hover: secondary background. Overflow: ellipsis with nowrap. Action buttons right-aligned in action column.

### Form grid

Two-column responsive grid (collapses to single column on narrow viewports). Each field: label (11px, 500, secondary colour) above input. Input: 12px, 6px padding, secondary border, md border-radius, primary background. Textarea: resizable, 60px min height. All inputs full-width within their column.

### Action row

Right-aligned flex row. Border-top separator, 10px top margin, 10px top padding. Order (left to right): Cancel / Back → secondary actions → primary action. Primary button always rightmost.

---

## 5. Access control & role rules

### Admin portal — visible pages vs member portal

The following are **admin-only** and not accessible to members:

| Admin-only page / data | Reason |
|---|---|
| Fund tier utilisation and allocation | Operational, not relevant to individual member |
| Loan queue for all members | Members only see their own requests |
| Reconciliation exceptions and resolution | Internal financial control |
| Bank clearing and import | Back-office operation |
| Audit log | Internal compliance |
| Configuration | System administration |
| Migration management | Admin-initiated operation |
| All member balances in aggregate | Privacy |
| Other members' account details | Privacy |

### Member portal — what members cannot see or do

| Restricted action | Reason |
|---|---|
| View other members' data | Privacy |
| Approve or reject loan requests | Admin only |
| Post manual journal entries | Admin only |
| Access reconciliation | Internal |
| Change system configuration | Admin only |
| Override eligibility gates | Admin only |
| Grant clearance for migration | Admin only |
| Import bank statements | Admin only |
| View master account balances | Internal |

### Admin override actions requiring additional logging

All admin overrides must record:
- The admin user ID
- Timestamp
- The gate or rule overridden
- A mandatory reason (free text, minimum 10 characters)
- The affected member / loan / exception ID

These entries appear in the Audit log under the "Overrides" filter.

---

## Appendix — Page inventory summary

### Admin portal

| Page | Sub-pages / tabs | Key actions |
|---|---|---|
| Dashboard | — | Run batch, navigate to sub-systems |
| Members | List, Detail (5 tabs), New member | Add, View, Edit, Suspend, Override |
| Loan queue | List, Review/approve | Approve, Partial approve, Reject |
| Collection cycles | List view | Run debit batch, Post manual collection |
| Disbursements | List, Post tranche | Disburse tranche, View loan |
| Reconciliation | Exception queue, Resolve detail | Run batch, Resolve, Escalate, Write-off |
| Bank clearing | Unmatched, Matched | Import statement, Match manually, Adjust & clear |
| Reports | Standard reports, Custom builder | Download, Generate |
| Migration | Member list, Stub resolution | Batch classify, Individual classify, Grant clearance |
| Configuration | 5 config tabs | Save per tab |
| Audit log | Filtered table | Export |

### Member portal

| Page | Sub-pages / tabs | Key actions |
|---|---|---|
| Overview | — | Navigate to sub-pages, quick deposit/cashout/loan/settle |
| Cash account | Balance + deposit form + history | Deposit, download history |
| Fund account | Balance detail + history | Download statement |
| My loans | Active (schedule), History, Settle | Full settle, Partial settle (roll-up or skip) |
| Loan request | 4-step wizard | Submit request, save draft |
| Contributions | History table + metrics | Export |
| Transactions | Filterable ledger | Filter by type, Export |
| Cash out | Request form + history | Submit cash out |
| Statements | Generator + recent downloads | Generate, Download |
| Settings | Profile, Notifications, Bank, Security | Save per tab, Update password |
| Help & FAQ | Accordion FAQ | Expand answers, Contact support |
