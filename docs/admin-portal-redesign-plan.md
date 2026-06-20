# FundFlow Admin Portal Redesign Plan
**Version:** 1.0  
**Date:** June 2026  
**Branch:** `feature/admin-portal-redesign` (to be created before implementation)  
**Reference prototype:** `docs/Claude/admin-portal-prototype.html`

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current State Analysis](#2-current-state-analysis)
3. [Prototype Reference Analysis](#3-prototype-reference-analysis)
4. [Design System & Visual Language](#4-design-system--visual-language)
5. [Information Architecture Redesign](#5-information-architecture-redesign)
6. [Dashboard — Command Centre](#6-dashboard--command-centre)
7. [Page-by-Page Redesign Plan](#7-page-by-page-redesign-plan)
8. [Missing Features to Add](#8-missing-features-to-add)
9. [Bilingual (AR / EN) Implementation Plan](#9-bilingual-ar--en-implementation-plan)
10. [Implementation Phases](#10-implementation-phases)
11. [Technical Approach & Constraints](#11-technical-approach--constraints)
12. [Success Criteria](#12-success-criteria)

---

## 1. Executive Summary

The current FundFlow **tenant admin portal** (`/admin`) is functionally complete but visually fragmented and operationally overwhelming. It uses the Filament Sky-blue default aesthetic, a 20+ item sidebar, and individual insight heroes on every list page that create a noisy, consultant-report feel rather than an operational command centre.

**Goal:** Redesign the tenant admin portal to match the visual language and layout philosophy of the attached prototype — clean white panels, purple/indigo primary palette, semantic colour chips, and a consolidated dashboard from which an admin can see everything and trigger most actions — while preserving all existing domain logic, adding missing features, and delivering full Arabic/English bilingual support.

The redesign does **not** change Filament's PHP back-end or routing. It targets:
- CSS design tokens and panel atmosphere
- Dashboard widget architecture and layout
- Sidebar information architecture
- Per-page widget consolidation
- New standalone pages for missing features
- Blade template and component redesign
- AR/EN string catalogue completion

---

## 2. Current State Analysis

### 2.1 Panel Identity

| Attribute | Current Value | Problem |
|-----------|--------------|---------|
| Primary colour | `Color::Sky` (Tailwind sky/blue) | Does not match prototype purple; differs from member portal |
| Page atmosphere | Soft blue radial gradient background | Fine but inconsistent with prototype's crisp white |
| Sidebar width | Filament default (~256px) | Too wide; prototype uses 192px compact sidebar |
| Dashboard layout | `Width::Full` single widget | Widget renders a multi-section Blade view — opaque to Filament |

### 2.2 Sidebar Navigation Overload

The sidebar currently exposes **22 named items** before scrolling (including relation manager tabs, hidden redirects, and SMS sub-resources). By count:

| Group | Items | Issue |
|-------|-------|-------|
| (ungrouped) | Dashboard, Messages | Dashboard buried under ungrouped items |
| Accounts | Bank Accounts, Master Accounts, Member Accounts, Year-end close | 4 items for what prototype shows as 1 grouped concept |
| Fund Management | Applications, Members, Requests, Support, Deposits, Cash outs, Contributions, Loans (cluster), Statements, Reconciliation | 10 items; cluster adds 4 more sub-nav tabs |
| System | Jobs, Maintenance, Audit, Migration, Settings, Notification logs | 6 items; most rarely needed |

**Result:** An admin on duty sees a wall of navigation and must remember which page holds which action.

### 2.3 Dashboard Widget Fragmentation

The current dashboard mounts a single `TenantDashboardWidget` Blade view that contains:
- Greeting hero with fund name
- Quick-action gradient tiles (6 tiles with large gradient backgrounds)
- Contribution insights (via partial includes)
- Loan pipeline (via partial includes)
- Recent activity log

Problems:
- Quick-action tiles are large colourful gradient blocks — the "busy" the user identified
- No real-time urgency indicators on the dashboard itself (reconciliation alerts, overdue queue count)
- No consolidated loan queue preview
- Collection progress buried inside partials rather than shown as a top-level metric
- Cannot act from the dashboard — tiles navigate to list pages, not action modals

### 2.4 Per-Page Insight Heroes

Every major list page (Members, Loans, Contributions, Deposits, Cash outs, Bank Accounts, Master Accounts) mounts a header widget (`*InsightsWidget`) that renders a separate KPI strip above the table. These are valuable data but:
- Each follows a slightly different layout and colour scheme
- They duplicate high-level stats already available on the dashboard
- They slow down perceived page load (two widget mounts per page)

### 2.5 Missing Features

Compared to the prototype and operational needs:

| Missing | Prototype has it? | Impact |
|---------|-------------------|--------|
| Fund pool health gauge (total cash/fund, solvency ratio) | Partially | Critical — admin cannot see pool health at a glance |
| Loan queue review workflow triggered from dashboard | Yes (Review button) | High |
| Collection cycle drill-down from dashboard | Yes (member list with status) | High |
| Member activity feed (real-time, dashboard-level) | Yes | Medium |
| Fund tier utilisation bar charts | Yes | High — shows when a tier is near capacity |
| Bank clearing summary on dashboard | Yes | High |
| Custom report builder | Yes | Medium |
| Bulk notification / announcement to members | No (prototype) | High — currently no admin-to-member broadcast |
| Member suspension / status change from list | Yes (action buttons) | Medium |
| Guarantor health view (how many loans each member guarantees) | No | Medium |
| EMI collection calendar view | No | Medium |
| SMS/notification delivery health log | No | Low |

### 2.6 Bilingual Gaps

- All nav labels and page titles are English-only
- Admin-facing notifications, Stat labels, section headings use `translateLabel()` but many custom Blade views have hard-coded English strings
- Arabic numerals are used in some places (should be Western digits throughout per requirement)
- Money symbol inconsistency: `SAR` in English vs `ر.س` / `﷼` in Arabic — not systematically applied
- RTL layout not applied to the admin portal (sidebar, table direction)

---

## 3. Prototype Reference Analysis

The reference prototype (`docs/Claude/admin-portal-prototype.html`) is an HTML/Alpine.js/Tailwind single-page mock-up of a cooperative fund admin portal. Key design decisions to adopt:

### 3.1 Colour Palette (to become the new design token system)

| Token | Hex | Role |
|-------|-----|------|
| `--ff-primary` | `#534AB7` | Primary actions, active nav, primary buttons |
| `--ff-primary-dark` | `#3C3489` | Hover state |
| `--ff-primary-light` | `#EEEDFE` | Primary tint backgrounds |
| `--ff-success` | `#1D9E75` | Collected, active, cleared |
| `--ff-success-light` | `#E1F5EE` | Success chip backgrounds |
| `--ff-warning` | `#EF9F27` | Overdue T1, partial, pending |
| `--ff-warning-light` | `#FAEEDA` | Warning chip backgrounds |
| `--ff-danger` | `#E24B4A` | Critical, delinquent, rejected |
| `--ff-danger-light` | `#FCEBEB` | Danger chip backgrounds |
| `--ff-info` | `#378ADD` | Bank, info notices, panel links |
| `--ff-info-light` | `#E6F1FB` | Info chip backgrounds |
| `--ff-surface` | `#FFFFFF` | Card/panel backgrounds |
| `--ff-page-bg` | `#F9FAFB` | Page canvas |
| `--ff-border` | `#E5E7EB` | Card borders, dividers |
| `--ff-muted` | `#6B7280` | Secondary labels |
| `--ff-muted-light` | `#9CA3AF` | Tertiary labels, table headers |

**Primary colour stays `Color::Sky`** — the tenant admin portal deliberately uses sky-blue to visually differentiate from the member portal (which uses purple `#534AB7`). All semantic chip/notice/progress token colours are adopted from the prototype but the primary interactive colour (buttons, active nav, focus rings) remains sky-blue.

### 3.2 Layout System

```
┌─────────────────────────────────────────────┐
│  Sidebar 192px │  Topbar 50px (sticky)       │
│  (fixed)       │─────────────────────────────│
│                │  Page content (scrollable)  │
│  Logo          │  ┌─────────────────────────┐│
│  Nav groups    │  │  Stat grid (4-col)      ││
│  Nav items     │  │  Panel grid (flexible)  ││
│  ─────         │  │  Tables                 ││
│  User avatar   │  └─────────────────────────┘│
└────────────────┴────────────────────────────┘
```

**Sidebar (192px):**
- Logo block (26×26px branded icon + fund name)
- Navigation groups with 10px uppercase labels
- Nav items: 12px, 7px vertical padding, 8px radius, hover `#F3F4F6`
- Badge counts on items with urgent queues
- User avatar block at bottom (initials avatar + role label)

**Topbar (50px sticky):**
- Page title (14px, 600 weight, flex-1)
- Cycle period chip (current collection cycle)
- Notification bell
- Context-specific primary action button
- Export button

**Content:** 20px padding, no horizontal scroll on primary tables

### 3.3 Component Vocabulary

| Component | Prototype class | Usage |
|-----------|----------------|-------|
| Stat card | `.stat-card` | 4-column KPI grid at page top |
| Panel | `.panel` + `.panel-head` + `.panel-body` | All content containers |
| Chip | `.chip chip-{green/amber/red/blue/purple/gray}` | Status badges everywhere |
| Notice | `.notice notice-{red/amber/blue/green}` | Alert/warning callouts |
| Progress bar | `.prog-bar` + `.prog-fill` | Collection %, tier utilisation |
| Table | `.tbl` | All data grids |
| Button | `.btn btn-{primary/gray/success/danger}` | All actions |
| Detail grid | `.detail-grid` + `.detail-item` | 2-col key-value in panels |
| Tab bar | `.tab-bar` + `.tab-btn` | Sub-navigation within a page |

**In Filament context:** These map to:
- Stat card → Filament `Stat` widget or custom `StatsOverview` widget
- Panel → Filament `Section`
- Chip → `BadgeColumn` / `TextColumn` with colour
- Notice → Filament `Placeholder` with custom Blade view or notification component
- Table → Filament `Table`
- Button → Filament `Action`

### 3.4 Dashboard Layout (Prototype Reference)

The prototype dashboard is a single scrollable page with:

```
Row 1:  [Total Members] [Collected %] [Active Loans] [Recon Exceptions]   ← 4 stats
Row 2:  [Loan Queue preview 1.4fr] | [Recon Alerts 1fr]                   ← 2-col panels
Row 3:  [Cycle Progress 1fr] | [Recent Activity 1fr] | [Fund Tiers 1fr]   ← 3-col panels
```

Every panel has a "View all →" link and actionable buttons (Review, Resolve).

---

## 4. Design System & Visual Language

### 4.1 Filament Primary Colour — Sky Blue (unchanged)

**File:** `app/Providers/Filament/TenantPanelProvider.php`

```php
// Stays as-is — sky-blue differentiates admin from the purple member portal
->colors(['primary' => Color::Sky])
```

The primary interactive colour (buttons, focus rings, active nav, form focus) stays sky-blue. The prototype's purple is used only on the member portal. Semantic colours (success green, warning amber, danger red, info blue) are adopted from the prototype's token system.

### 4.2 Tenant Theme CSS (`resources/css/filament/tenant/theme.css`)

The background gradient is kept but significantly toned down (opacity reduced ~50%) — the sky atmosphere is preserved as a brand identifier but no longer dominates the page. The token variables layer on top without removing any existing rules, so all current quick-action tiles and maintenance styles continue to work while the new component classes build the prototype aesthetic.

Phase 0 (✅ complete) added:
- `--ff-*` CSS custom properties (sky-blue primary, semantic chip/notice/progress colours)
- Reduced gradient opacity for a cleaner page feel
- `.ff-chip-*`, `.ff-notice-*`, `.ff-prog-*` base component classes
- Sidebar right-border and panel border-radius tokens

Phase 1 (dashboard) will add compact quick-action bar CSS replacing the large gradient tiles.

### 4.3 Design Token CSS Variables

Add to `resources/css/filament/tenant/theme.css`:

```css
.fi-body.fi-panel-tenant {
    --ff-primary: #534ab7;
    --ff-primary-dark: #3c3489;
    --ff-primary-light: #eeedfe;
    --ff-success: #1d9e75;
    --ff-success-light: #e1f5ee;
    --ff-warning: #ef9f27;
    --ff-warning-light: #faeeda;
    --ff-danger: #e24b4a;
    --ff-danger-light: #fcebeb;
    --ff-info: #378add;
    --ff-info-light: #e6f1fb;
    --ff-surface: #ffffff;
    --ff-page-bg: #f9fafb;
    --ff-border: #e5e7eb;
    --ff-muted: #6b7280;
    --ff-muted-light: #9ca3af;
    --ff-panel-radius: 12px;
}
```

These tokens are reused across all new Blade views, ensuring visual consistency.

### 4.4 Typography Scale

| Use | Size | Weight |
|-----|------|--------|
| Page title (topbar) | 14px / 0.875rem | 600 |
| Panel title | 12px / 0.75rem | 600 |
| Nav group label | 10px / 0.625rem | 600 UPPERCASE |
| Nav item | 12px / 0.75rem | 400/500 |
| Table header | 10px / 0.625rem | 600, muted |
| Table cell | 12px / 0.75rem | 400 |
| Stat number | 20–22px | 700 |
| Stat label | 10px | 400, muted |
| Stat sub-label | 11px | 400, coloured |
| Chip | 10px | 600 |

Arabic text uses `Noto Sans Arabic` from the existing font stack. Numeric values always rendered in Western (Latin) digits — `font-variant-numeric: normal` forced on Arabic locale pages.

---

## 5. Information Architecture Redesign

### 5.1 Sidebar Consolidation

The prototype uses 10 navigation items across 3 groups. Map current 22-item sidebar to 10 items:

#### New sidebar structure

```
[Logo: FundAdmin + fund name]

OPERATIONS
  📊 Dashboard           ← unchanged, always first
  👥 Members             ← unchanged
  💰 Loans               ← merge: Loan Resource + Loan Queue (tab on index) + EMI Collection
  📅 Collections         ← rename ContributionResource; cycle page merged in
  💳 Disbursements       ← new: surfaces disbursement workflow prominently (was buried in Loans)

FINANCE
  🔄 Reconciliation      ← unchanged (page)
  🏦 Bank clearing       ← rename BankAccountsResource (merged with Master Accounts context)
  📈 Reports             ← NEW standalone page (see §8)

SYSTEM
  ⚙️  Settings           ← merge: Settings page + Configuration tabs
  📋 Audit log           ← merge: Audit + Notification logs + Jobs tabs
```

**Zero features are removed.** Items moved off the sidebar are fully accessible via:

| Feature | New access path |
|---------|----------------|
| Deposits (Fund Postings) | Members → member detail → Accounts tab → "Post deposit" action; also from Bank Clearing sidebar item |
| Cash outs | Members → member detail → Accounts tab; also Disbursements page |
| Master Accounts | Bank Clearing page → contextual master account balance panel |
| Member Accounts | Members → member → Accounts tab |
| Monthly Statements | Reports page → "Member statements (bulk)"; also member detail → Accounts |
| Membership Applications | Dashboard alert card + Members sidebar (filter: pending applications) |
| Member Requests | Dashboard activity feed + Messages inbox |
| Support Requests | Messages inbox → support ticket view |
| Migration | Audit & System sidebar → Migration tab |
| Fiscal Year Close | Audit & System sidebar → Year-end close tab |
| System Maintenance | Audit & System sidebar → System maintenance tab |
| Messages inbox | Topbar notification bell + accessible from sidebar Audit & System |
| SMS Import Sessions | Bank Clearing → SMS channel tab |
| SMS Templates | Bank Clearing → SMS channel tab → Templates sub-tab |
| Loan Overrides | Loans → loan detail → override action |
| Reconciliation Exceptions | Reconciliation page → exception queue table |
| Notification Logs | Audit & System → Notification log tab |
| Jobs & Commands | Audit & System → Jobs tab |

**Badge counts on sidebar:**
- Loans badge = count of pending loan queue items
- Collections badge = count of overdue members in active cycle
- Reconciliation badge = count of open exceptions (red when critical)
- Bank clearing badge = count of unmatched bank lines

### 5.2 Navigation Groups Rename

| Current | New | Reason |
|---------|-----|--------|
| Accounts | FINANCE | More meaningful grouping |
| Fund Management | OPERATIONS | Operational intent is clearer |
| System | SYSTEM | Unchanged |

### 5.3 Topbar Simplification

Current topbar is Filament default. New topbar additions:
- **Cycle chip:** "📅 Cycle: Jun 2026" — shown on all pages, links to Collections
- **Notification bell** with unread count (already via `databaseNotifications`)
- **Primary action** button that changes per page context (prototype pattern)
- **Export** button (shown on list pages only)
- **RTL toggle** for admin preference (EN/AR language switcher — existing component)

---

## 6. Dashboard — Command Centre

The redesigned dashboard is the operational heart of the admin portal. An admin starting their day should immediately see: pool health, collection status, urgent loan requests, and reconciliation alerts — and should be able to act on most of them without leaving the dashboard.

### 6.1 Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│  TOPBAR: Dashboard  |  📅 Jun 2026  |  🔔  |  [Run batch]  [Export] │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ROW 1 — KPI Strip (4 stat cards, equal width)                      │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐               │
│  │👥 Members│ │✅ Cycle  │ │💰 Loans  │ │⚠ Recon  │               │
│  │  248     │ │  83%     │ │  34      │ │  2       │               │
│  │+3 cycle  │ │ 187/248  │ │  7 queue │ │  1 crit. │               │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘               │
│                                                                      │
│  ROW 2 — Action Panels (60% / 40% split)                            │
│  ┌─────────────────────────────┐ ┌──────────────────────┐           │
│  │ 💰 Loan Queue (top 4)       │ │ 🔄 Reconciliation    │           │
│  │ [Review] buttons inline     │ │ alerts + notices     │           │
│  │ "View all →"                │ │ "Open queue →"       │           │
│  └─────────────────────────────┘ └──────────────────────┘           │
│                                                                      │
│  ROW 3 — Insight Panels (3 equal columns)                           │
│  ┌───────────────┐ ┌────────────────┐ ┌───────────────────┐        │
│  │ 📅 Collection │ │ ⚡ Recent       │ │ 📊 Fund Tier       │       │
│  │ progress bars │ │ activity feed  │ │ utilisation bars  │        │
│  │ + late tiers  │ │ (last 8 events)│ │ + warnings        │        │
│  └───────────────┘ └────────────────┘ └───────────────────┘        │
│                                                                      │
│  ROW 4 — Ancillary (optional, collapsed by default)                 │
│  ┌──────────────────────┐ ┌──────────────────────────────┐         │
│  │ 🏦 Bank clearing     │ │ 📋 Pending membership        │         │
│  │ summary + unmatched  │ │ applications                 │         │
│  └──────────────────────┘ └──────────────────────────────┘         │
└──────────────────────────────────────────────────────────────────────┘
```

### 6.2 Dashboard Widgets

Replace the current single `TenantDashboardWidget` Blade view with discrete Filament widgets. This makes each panel independently refreshable, testable, and movable.

| Widget class | Panel shown | Data source |
|---|---|---|
| `AdminKpiStripWidget` | KPI stat cards (row 1) | `TenantDashboardService::snapshot()` |
| `LoanQueuePreviewWidget` | Loan queue top 4 with Review actions (row 2 left) | `LoanService::queuePreview()` |
| `ReconAlertsWidget` | Reconciliation notices (row 2 right) | `ReconciliationService::openExceptions()` |
| `CollectionProgressWidget` | Cycle progress bars + late tier breakdown (row 3 left) | `ContributionCycleService::cycleSnapshot()` |
| `RecentActivityWidget` | Feed of last 8 admin-relevant events (row 3 centre) | `FundAuditLogService::recentFeed()` |
| `FundTierUtilisationWidget` | Tier capacity bars + capacity alerts (row 3 right) | `FundTierService::utilisationSnapshot()` |
| `BankClearingSummaryWidget` | Bank unmatched count + amount (row 4 left) | `BankService::clearingSummary()` |
| `PendingApplicationsWidget` | Applications awaiting approval (row 4 right) | `MembershipApplicationService::pending()` |

### 6.3 Dashboard Quick Actions

Replace current large gradient tiles with a compact **quick actions row** inside `AdminKpiStripWidget` or as a standalone narrow component:

```
[ ▶ Run debit batch ]  [ + Add member ]  [ ⬆ Import bank statement ]  [ 📊 Generate report ]
```

These are small `btn-gray` buttons, not coloured tiles. The primary action for the current page remains in the topbar.

---

## 7. Page-by-Page Redesign Plan

### 7.1 Members

**Current:** List page + insights header widget + relation manager tabs (Accounts, Contributions, Repayments, Loans, Dependents, Messages)

**Changes:**
- Remove `MemberInsightsWidget` header — fold KPIs into the topbar stat strip (member count, active/overdue breakdown)
- Add filter tabs below search bar (All / Active / Migration pending / Delinquent / Suspended) matching prototype pill tabs
- Table columns: ID, Name, Status chip, Cash balance, Fund balance, Active loans, Actions
- Row actions: **View** (opens detail) + **Edit** + (context) **Suspend / Reinstate** — wrapped in action group
- Member detail page (EditMember): Convert to tab-based layout matching prototype:
  - **Profile** tab: 2-col detail grid + activity timeline widget
  - **Accounts** tab: Cash account panel + Fund account panel + manual entry action
  - **Loans** tab: Active loan detail + repayment schedule + settle actions
  - **Cycle history** tab: Contribution history table (per cycle)
  - **Transactions** tab: Full ledger table

### 7.2 Loans

**Current:** LoanResource with queue/list/view/edit pages + LoanInsightsWidget header + Loans cluster sub-nav

**Changes:**
- Rename cluster nav item to "Loans" (unchanged)
- **List (portfolio) page:** Remove `LoanInsightsWidget` header; embed compact KPI strip (active/repaid/delinquent counts + total disbursed) as a `Section` at top — collapsible
- **Loan queue page:** Redesign as the primary action page: filter tabs (All / Emergency / Standard / Partial), table with inline Approve/Reject/Partial buttons matching prototype
- **Loan review page:** Dedicated full page (already exists as `ViewLoan`) — eligibility check rows with pass/fail chips, decision form with amount override, admin notes, confirm/reject buttons
- **EMI collection:** Surface as a dashboard-accessible action (already exists as `LoanEmiCollectionPage`) — add shortcut from Loans list "Collect EMIs" header action

### 7.3 Collections (Contributions)

**Current:** `ContributionResource` at "Contributions" sidebar item; `ContributionCyclePage` is a legacy redirect

**Changes:**
- Rename nav item "Contributions" → "Collections"
- Page redesign: Cycle selector at top (active cycle + past cycles as pills)
- KPI strip: Due / Collected / Overdue / Exempt stat cards
- Table: Member, Due amount, Status chip, Collected, Days late, Fee tier, Actions
- Header action: "▶ Run debit batch" prominent button
- Add "Per-member detail" modal: shows cycle history for a specific member
- Late fee escalation visibility: colour-code rows by tier (T1 = amber, T2 = orange, T3 = red)

### 7.4 Disbursements

**Current:** No dedicated Disbursements page — disbursement is triggered from Loan view page

**New page:** `DisbursementsPage` — surfaced in sidebar under OPERATIONS

Content:
- Active disbursements table: Member/Loan, Approved, Disbursed, Remaining, Status, Actions
- "Post tranche" form modal for partial loans
- Filter tabs: Pending / Partial / Fully disbursed
- KPI strip: Pending count, Total committed (SAR), Total disbursed (SAR), Awaiting bank match

### 7.5 Reconciliation

**Current:** `ReconciliationOverviewPage` — already close to prototype design

**Changes:**
- Add KPI strip at top: Open exceptions / Auto-resolved / Last batch run / Next batch
- Exception queue table: keep as is but add Severity chip colour coding
- Add "Recon history" tab showing resolved exceptions with resolution log
- Auto-resolved section: show last batch results
- Critical exception → red banner notice at top of page (matching prototype `notice-red`)

### 7.6 Bank Clearing

**Current:** `BankAccountsResource` → `ViewBankStatement` page with SMS workspace embedded

**Changes:**
- Rename nav item "Bank Accounts" → "Bank Clearing"
- Top KPI strip: Imported today / Auto-matched / Unmatched / Stale pending (>30d)
- Unmatched section first (action required) with Match manually / Adjust & clear buttons
- Matched section below (read-only)
- Import statement as prominent header action button
- SMS import channel: keep as a tab within the bank workspace ("SMS channel" tab)
- Master account balances: shown as a contextual summary panel within the same page

### 7.7 Reports (New)

**Current:** No dedicated reports page — statements are a resource, no custom report builder

**New page:** `ReportsPage` — surfaced in sidebar under FINANCE

Content:
- Standard reports grid: Each as a clickable card with PDF download button
  - Monthly collection report
  - Loan portfolio report
  - Reconciliation summary
  - Fund tier utilisation
  - Member statements (bulk)
  - Guarantor exposure report (new)
  - Audit trail export
- Custom report builder panel:
  - Report type selector
  - Date range pickers
  - Member filter (optional)
  - Format selector: PDF / Excel / CSV
  - Generate button
- Scheduled reports section (future phase)

### 7.8 Settings / Configuration

**Current:** `Settings` page with general fund settings; configuration is split across various resource edit pages

**Changes:**
- Expand Settings page with tabbed configuration matching prototype:
  - **General** tab: Fund name, cycle day, bank details
  - **Collection** tab: Window days, late fee model, tier thresholds and amounts
  - **Loans** tab: Min tenure, min fund balance, borrow multiplier, max active loans, settlement threshold, grace period options
  - **Fund tiers** tab: Tier A/B/C/E allocations (currently in `FundTierResource` edit)
  - **Reconciliation** tab: Auto-resolve tolerance, timing defer, bank match range, stale threshold
  - **Guarantor rules** tab: Missed EMI thresholds for warning and transfer
  - **Notifications** tab: Which events trigger SMS/email/push; templates per event type

### 7.9 Audit / System

**Current:** `FundAuditLogResource` (Audit log) + `JobsPage` + `SystemMaintenancePage` + `NotificationLogResource` + `LegacyMigrationPage` + `FiscalYearClosePage`

**Changes:**
- Merge into a single "Audit & System" page with tabs:
  - **Audit log** tab: Full audit table with filter pills (All / Admin actions / Overrides / Recon events / Loan events)
  - **Notification log** tab: SMS/email/push delivery status
  - **Jobs** tab: Scheduled jobs table + run actions
  - **System maintenance** tab: DB backup/purge widgets
  - **Migration** tab: Legacy data import workflow
  - **Year-end close** tab: Fiscal year close workflow

---

## 8. Missing Features to Add

### 8.1 Fund Pool Health Panel (Dashboard)

A new dashboard section showing:
- Total master fund balance vs total member fund balances (should equal)
- Total master cash vs total member cash balances
- Pool solvency ratio: (cash + fund) / total loan commitments
- 30-day trend sparkline
- Alert if any drift detected (links to Reconciliation)

### 8.2 Reports Page (§7.7)

Full custom report builder — see above.

### 8.3 Bulk Member Announcement / Notification

**New feature:** Admin can broadcast a message to:
- All active members
- Members in a specific status (overdue, delinquent)
- Members with active loans
- A manually selected list

Delivery channels: in-app notification + SMS (if SMS configured) + email.

UI location: Messages inbox page → "Compose announcement" action.

### 8.4 Guarantor Exposure Report

A new report / page view showing:
- Each member who is a guarantor, and for which loans
- Their total guaranteed exposure (SAR)
- Whether any of their guaranteed loans are in arrears (risk flag)

Helps admin understand systemic guarantor risk.

### 8.5 EMI Collection Calendar

Visual calendar view (month grid) showing:
- Which EMIs are due on which cycle days
- Which have been collected, which are pending
- Colour-coded by loan status

Accessible from Loans page header.

### 8.6 Member Request / Support Ticket Workflow

**Current:** `MemberRequestResource` and `SupportRequestResource` are list-only tables with no workflow actions.

**New:** Each support request should have:
- Status: Open / In progress / Resolved / Closed
- Admin reply field (sends message to member)
- Escalation flag
- SLA indicator (days open)

Integrated with Messages inbox.

### 8.7 Disbursements Page

See §7.4 — this surfaces the disbursement workflow that is currently buried inside the Loan view page.

### 8.8 Loan Queue Priority Scoring

In the loan queue list, add a computed "Priority score" column based on:
- Request type (Emergency > Standard)
- Days in queue (longer = higher)
- Member tenure and standing

Sort queue by score by default.

---

## 9. Bilingual (AR / EN) Implementation Plan

### 9.1 Scope

Every user-facing string in the admin portal must be translatable. This includes:
- Navigation labels and groups
- Page titles and breadcrumbs
- Section headings, panel titles
- Table column headers
- Status chips, filter pill labels
- Action button labels
- Notification titles and bodies
- Settings labels, placeholders, help text
- Report names and descriptions
- Error messages and validation
- PDF-exported content (statements, reports)

### 9.2 String Catalogue Files

| File | Contents |
|------|----------|
| `lang/en.json` | Primary English strings (already exists) |
| `lang/ar.json` | Arabic translations (extend existing) |
| `lang/en/admin.php` | Long-form English strings for admin portal sections |
| `lang/ar/admin.php` | Arabic translations of admin.php |

All new strings use namespaced keys: `admin.nav.loans`, `admin.dashboard.kpi.members`, `admin.settings.collection.window_days`, etc.

### 9.3 Money Symbol Bilingual Treatment

| Context | EN display | AR display |
|---------|-----------|-----------|
| Amounts | `SAR 1,234.50` | `1,234.50 ر.س` |
| Currency chip | `SAR` | `ر.س` |
| Compact amounts | `SAR 1.2K` | `1.2K ر.س` |
| Numeric digits | Western always | Western always (no Arabic-Indic digits) |

Implementation: Extend `MoneyDisplay` support class to detect locale and format accordingly. The existing `app/Support/MoneyDisplay.php` is already modified on the redesign branch — adopt the same pattern for admin.

### 9.4 RTL Layout

When the admin's locale is Arabic:
- `<html dir="rtl">` applied via middleware (already done for member portal via `SetApplicationLocale`)
- Sidebar aligns to the right
- Table text-right for Arabic strings, text-left for numbers (achieved via `tabular-nums` + explicit `dir="ltr"` on amount columns)
- Arrow icons flip (CSS `transform: scaleX(-1)` on `.rtl` context for breadcrumb arrows, "View all →" links)
- Form labels and inputs: RTL text flow, but numeric inputs remain LTR

### 9.5 Filament `translateLabel()` Usage

Per workspace rules:
- All `Column`, `FormField`, `Action`, `Filter`, `Tab`, `FieldSet` automatically translated via `translateLabel()` (registered globally in `AppServiceProvider`)
- Wrap remaining user-facing literals: `Section::make(__('admin.section.…'))`, `Notification::make()->title(__('…'))`, stat labels, modal headings

### 9.6 Arabic Font Rendering in PDFs

DomPDF requires Arabic text shaping (already handled by `DomPdfFactory` in existing codebase). All new PDF templates:
- Import Arabic fonts via `resources/views/partials/arabic-fonts.blade.php`
- Use `<span dir="rtl" lang="ar">` for Arabic segments
- Apply `font-family: 'Amiri', 'Noto Naskh Arabic', sans-serif` for Arabic blocks

---

## 10. Implementation Phases

### Phase 0 — Branch & Token Setup *(1 day)*

1. Create `feature/admin-portal-redesign` branch from `main`
2. Update `TenantPanelProvider`: change primary colour to `#534AB7`
3. Refactor `resources/css/filament/tenant/theme.css`:
   - Add CSS token variables
   - Replace blue atmosphere with white crisp background
   - Slim sidebar to 192px
   - Compact nav items (12px, 7px padding, 8px radius)
4. Run `npm run build` and verify visual change on dashboard
5. No functional changes in Phase 0 — purely aesthetic

**Verification:** Dashboard renders with purple primary, white sidebar, no gradient background.

---

### Phase 1 — Dashboard Redesign *(3–4 days)*

1. Split `TenantDashboardWidget` into 6 discrete widgets (§6.2)
2. Build `AdminKpiStripWidget` with 4 stat cards
3. Build `LoanQueuePreviewWidget` with Review action buttons
4. Build `ReconAlertsWidget` with notice components
5. Build `CollectionProgressWidget` with progress bars + late tier breakdown
6. Build `RecentActivityWidget` with activity feed
7. Build `FundTierUtilisationWidget` with capacity bars
8. Register all in `Dashboard.php` with correct column spans
9. Remove large gradient quick-action tiles; replace with compact action bar
10. Add AR translations for all new strings

**Verification:** Dashboard shows all panels; Review buttons open loan review modal/page; Recon alert links to reconciliation page.

---

### Phase 2 — Sidebar IA Consolidation *(2 days)*

1. Update `TenantNavigation.php` with new group structure (OPERATIONS / FINANCE / SYSTEM)
2. Rename nav items as planned
3. Move hidden-nav items to appropriate parent pages
4. Add badge counts (loan queue, overdue collections, recon exceptions, bank unmatched)
5. Create stub `DisbursementsPage.php`
6. Create stub `ReportsPage.php`
7. Add topbar: cycle chip, notification bell position, language switcher

**Verification:** Sidebar shows 10 items; badge counts update with live data; existing routes still work.

---

### Phase 3 — Members & Member Detail *(2–3 days)*

1. Remove `MemberInsightsWidget` from list header; embed compact KPI section
2. Add filter pill tabs (All / Active / Migration / Delinquent / Suspended)
3. Redesign member detail (edit) page with prototype tab layout
4. Add profile + activity timeline tab
5. Add accounts tab with cash/fund account panels
6. Add cycle history tab
7. Loans and transactions tabs already exist — visual cleanup only
8. AR translations for all member page strings

---

### Phase 4 — Loans & Collections *(2 days)*

1. Redesign loan queue page with priority scoring column and inline actions
2. Redesign loan review page with eligibility check rows (chip-based pass/fail)
3. Rename Contributions → Collections; add cycle selector pills
4. Add late tier colour coding to collections table
5. Add collection batch run as header action button
6. AR translations

---

### Phase 5 — Disbursements Page *(1 day)*

1. Build `DisbursementsPage` with active disbursements table
2. Add "Post tranche" modal (ported from existing Loan view action)
3. Add KPI strip
4. AR translations

---

### Phase 6 — Reconciliation & Bank Clearing *(2 days)*

1. Add KPI strip to Reconciliation page
2. Add critical exception banner notice (red)
3. Add recon history tab
4. Rename Bank Accounts → Bank Clearing
5. Add KPI strip to Bank Clearing page
6. Merge master account context into bank page panel
7. SMS channel as a tab in bank workspace (already partially done)
8. AR translations

---

### Phase 7 — Reports Page *(2 days)*

1. Build `ReportsPage` with standard report cards + PDF download actions
2. Build custom report builder form (type, date range, member filter, format)
3. Wire PDF/Excel/CSV generation to existing controllers and services
4. Add Guarantor Exposure report (new service method)
5. AR translations

---

### Phase 8 — Settings Expansion *(2 days)*

1. Expand `Settings` page with full tabbed configuration
2. Port Fund Tier configuration from `FundTierResource` edit into Settings > Fund Tiers tab
3. Add Reconciliation configuration tab
4. Add Notifications/Templates configuration tab
5. AR translations for all settings labels, placeholders, help text

---

### Phase 9 — Audit/System Consolidation *(1 day)*

1. Merge Audit log, Notification log, Jobs, System maintenance, Migration, Fiscal year into a single tabbed Audit & System page
2. Reduce System sidebar group to 1 item: "Audit & System"
3. Keep Settings as separate item
4. AR translations

---

### Phase 10 — Missing Features *(3–4 days)*

1. Fund Pool Health panel on dashboard
2. Bulk member announcement feature (compose + send)
3. Guarantor exposure view (in Member detail + Reports)
4. EMI collection calendar view
5. Support ticket workflow (reply + status transitions)
6. Loan queue priority scoring algorithm

---

### Phase 11 — Bilingual Completion & PDF *(2 days)*

1. Full AR string catalogue audit — ensure 100% coverage of all admin strings
2. RTL layout testing in Arabic locale
3. Money symbol bilingual formatting across all pages
4. PDF templates: AR/EN text rendering in DomPDF
5. Numeric digit lock (Western digits throughout)

---

### Phase 12 — QA, Tests & Launch *(2 days)*

1. Update/create Pest feature tests for all new pages
2. Architecture tests (FilamentTableStandardsTest coverage)
3. AR locale smoke tests
4. Browser/Dusk visual smoke tests
5. `vendor/bin/pint --dirty --format agent`
6. Final `npm run build`
7. PR to main

---

## 11. Technical Approach & Constraints

### 11.1 Filament v5 Constraints

- Filament v5 is installed; use v5 widget/panel APIs throughout
- Always use `search-docs` before implementing any Filament component
- No Filament overrides — use the `configureUsing` pattern for global defaults
- Custom Blade views use `x-filament::*` components where possible; fall back to `ff-admin-*` custom classes for prototype-faithful styling

### 11.2 Existing Service Layer (Preserve)

The following services are production-grade and must not change:
- `TenantDashboardService` — snapshot data for KPIs
- `ContributionCycleService` — cycle state machine
- `LoanLedgerService` — loan posting logic
- `MasterAccountInvariantService` / `MemberInvariantService` — reconciliation checks
- `FundPostingService` / `MemberCashOutService` — cash flow posting

All new widgets/pages consume these services via their existing public APIs or new read-only query methods.

### 11.3 No Schema Changes in Phases 0–9

Phases 0–9 are presentation-layer changes only. Database schema changes are limited to Phase 10 (missing features):
- `member_announcements` table (bulk notification feature)
- `support_request_replies` table (support ticket workflow)

### 11.4 Test Coverage Requirements

Per workspace rules, every change must be tested. Minimum:
- Feature test for each new page (renders without error, AR locale renders without error)
- Unit test for each new service method (e.g., priority scoring, pool health calculation)
- Architecture test update for new Filament table resources (filters, grouping, bulk actions)

### 11.5 Branch Strategy

```
main
└── feature/admin-portal-redesign
    ├── Phase 0 commits (tokens)
    ├── Phase 1 commits (dashboard)
    ├── ...
```

Each phase should end with a passing test suite and a commit. No squash — preserve history for review.

---

## 12. Success Criteria

| Criterion | Measure |
|-----------|---------|
| Visual match to prototype | Side-by-side comparison: colour system, component shapes, layout grid |
| Sidebar ≤ 10 items | Count of visible sidebar nav items |
| Dashboard is actionable | Admin can Review a loan, trigger batch run, and navigate to Recon alert without leaving dashboard area |
| All strings bilingual | Zero hard-coded English strings in Blade views; AR locale renders all labels in Arabic |
| Numeric digits Western throughout | No Arabic-Indic digits on any page in any locale |
| SAR vs ر.س correct | EN pages show `SAR`, AR pages show `ر.س` |
| All tests pass | `php artisan test --compact` exits 0 |
| Pint clean | `vendor/bin/pint --dirty --format agent` makes no changes |
| No N+1 queries | Eager-load audit passes on all new widgets |
| Mobile-first | All panels readable on 375px viewport without horizontal scroll |

---

*Document prepared for: FundFlow SaaS Admin Portal Redesign*  
*Next document: `docs/admin-portal-specification.md` (full product specification)*  
*Then: Implementation begins on `feature/admin-portal-redesign` branch, one phase at a time with confirmation between steps.*
