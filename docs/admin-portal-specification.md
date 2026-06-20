# FundFlow Admin Portal — Product Specification
**Version:** 1.0  
**Date:** June 2026  
**Panel:** Tenant admin portal (`/admin`)  
**Reference:** `docs/admin-portal-redesign-plan.md`, `docs/Claude/admin-portal-prototype.html`

---

## Table of Contents

1. [Product Overview](#1-product-overview)
2. [Users & Roles](#2-users--roles)
3. [Design System Specification](#3-design-system-specification)
4. [Navigation & Shell Specification](#4-navigation--shell-specification)
5. [Dashboard Specification](#5-dashboard-specification)
6. [Members Module](#6-members-module)
7. [Loans Module](#7-loans-module)
8. [Collections Module](#8-collections-module)
9. [Disbursements Module](#9-disbursements-module)
10. [Reconciliation Module](#10-reconciliation-module)
11. [Bank Clearing Module](#11-bank-clearing-module)
12. [Reports Module](#12-reports-module)
13. [Settings & Configuration Module](#13-settings--configuration-module)
14. [Audit & System Module](#14-audit--system-module)
15. [Bilingual Specification](#15-bilingual-specification)
16. [Output Documents Specification](#16-output-documents-specification)
17. [Missing Feature Specifications](#17-missing-feature-specifications)

---

## 1. Product Overview

### 1.1 Purpose

The FundFlow admin portal is the operational control centre for cooperative savings fund administrators. It enables admins to manage the full lifecycle of a cooperative: member onboarding, monthly contribution collection, loan applications and disbursement, bank reconciliation, and fund health monitoring.

### 1.2 Design North Star

> **"An admin should be able to understand the fund's health and take the most urgent action within 10 seconds of opening the dashboard."**

### 1.3 Design Principles

| # | Principle | Implementation |
|---|-----------|---------------|
| P1 | Dashboard as command centre | Top 4 urgent actions available from dashboard without navigation |
| P2 | No information overload | Max 10 sidebar items; per-page KPI strips are collapsible |
| P3 | Action-first | Every list row has a context action button visible; no buried menus |
| P4 | Semantic colour | Green = collected/active, amber = pending/partial, red = critical/overdue, blue = informational |
| P5 | Bilingual parity | Arabic version is fully equivalent — not a translation afterthought |
| P6 | Numeric consistency | All numbers always in Western (Latin) digits regardless of locale |
| P7 | Mobile-aware | All pages usable on tablet (768px+); primary list pages functional at 375px |
| P8 | Audit trail | Every admin action is logged with actor, timestamp, and entity reference |

---

## 2. Users & Roles

### 2.1 Admin Roles (Tenant Panel)

| Role | Access level | Typical user |
|------|-------------|-------------|
| **Super admin** | Full access to all modules including Settings, System maintenance, Fiscal year close | Fund director |
| **Fund admin** | Full operational access except Settings, Maintenance, Migration | Operations manager |
| **Finance officer** | Read/write on Collections, Disbursements, Reconciliation, Bank Clearing, Reports | Finance team member |
| **Read-only viewer** | View-only on all modules | Auditor, external observer |

Role enforcement uses Filament's built-in shield (`filament-shield`) which is already installed.

### 2.2 Language Preference

Each admin has a locale preference (stored in user profile). Default locale is English (`en`). Arabic (`ar`) can be set per user. The locale preference persists across sessions.

---

## 3. Design System Specification

### 3.1 Colour Tokens

Sky-blue primary is intentional — it distinguishes the admin portal from the member portal (purple `#534AB7`).

```
Primary:        #0284c7   (sky-600 — Filament Color::Sky)
Primary dark:   #0369a1   (sky-700, hover state)
Primary light:  #e0f2fe   (sky-100, tint backgrounds)
Primary border: #7dd3fc   (sky-300, focus rings)

Success:        #1D9E75
Success light:  #E1F5EE

Warning:        #EF9F27
Warning light:  #FAEEDA

Danger:         #E24B4A
Danger light:   #FCEBEB

Info:           #378ADD
Info light:     #E6F1FB

Surface:        #FFFFFF   (cards, panels)
Page bg:        #F9FAFB   (canvas)
Border:         #E5E7EB   (all borders)
Muted:          #6B7280   (secondary text)
Muted light:    #9CA3AF   (table headers, placeholders)
Text primary:   #111827   (headings, values)
```

### 3.2 Typography

| Element | Size | Weight | Colour | Notes |
|---------|------|--------|--------|-------|
| Page title | 14px | 600 | #111827 | Topbar |
| Section heading | 12px | 600 | #111827 | Panel head |
| Nav group label | 10px | 600 | #9CA3AF | ALL CAPS |
| Nav item | 12px | 400 | #6B7280 | Active: 500, #111827 |
| Table header | 10px | 600 | #9CA3AF | ALL CAPS |
| Table cell | 12px | 400 | #111827 | |
| Stat number | 20–22px | 700 | #111827 | |
| Stat label | 10px | 400 | #9CA3AF | |
| Stat sub-label | 11px | 400 | semantic | |
| Chip | 10px | 600 | semantic | |
| Button | 11px | 500 | — | |
| Form label | 11px | 600 | #6B7280 | |
| Form input | 12px | 400 | #111827 | |

Font stack: `Instrument Sans`, `Noto Sans Arabic`, system-ui, sans-serif

### 3.3 Component Specifications

#### Stat Card
```
Background: #FFFFFF
Border: 1px solid #E5E7EB
Border radius: 12px
Padding: 14px 16px
Label: 10px, #9CA3AF, margin-bottom 4px
Number: 22px, 700, #111827
Sub-label: 11px, semantic colour
```

#### Panel
```
Background: #FFFFFF
Border: 1px solid #E5E7EB
Border radius: 12px
Overflow: hidden
Panel head: padding 11px 14px, border-bottom 1px #E5E7EB
Panel title: 12px, 600, #111827
Panel link: 11px, #378ADD, cursor pointer
Panel body: padding 10px 14px
```

#### Status Chip
```
display: inline-block
font-size: 10px
padding: 2px 7px
border-radius: 20px
font-weight: 600

chip-green:  bg #E1F5EE, text #0F6E56
chip-amber:  bg #FAEEDA, text #854F0B
chip-red:    bg #FCEBEB, text #A32D2D
chip-blue:   bg #E6F1FB, text #185FA5
chip-sky:    bg #e0f2fe, text #0369a1
chip-gray:   bg #F3F4F6, text #6B7280
```

#### Notice/Alert
```
display: flex, align-items: flex-start, gap: 8px
padding: 10px 12px
border-radius: 10px
font-size: 12px
margin-bottom: 12px

notice-red:   bg #FCEBEB, border 1px #E24B4A, text #A32D2D
notice-amber: bg #FAEEDA, border 1px #EF9F27, text #854F0B
notice-blue:  bg #E6F1FB, border 1px #378ADD, text #185FA5
notice-green: bg #E1F5EE, border 1px #1D9E75, text #0F6E56
```

#### Progress Bar
```
Height: 6px
Background track: #E5E7EB
Border radius: 3px
Fill: semantic colour (green/amber/red/gray)
```

#### Button Variants
```
btn-primary:  bg #534AB7, text white, border #534AB7 | hover bg #3C3489
btn-gray:     bg white, text #6B7280, border #E5E7EB | hover bg #F3F4F6
btn-success:  bg #E1F5EE, text #0F6E56, border #1D9E75
btn-danger:   bg #FCEBEB, text #A32D2D, border #E24B4A

Size: padding 5px 12px, font-size 11px, border-radius 8px
```

#### Data Table
```
Header: font-size 10px, 600, #9CA3AF, ALL CAPS, bg #F9FAFB, border-bottom 1px #E5E7EB
Cell: padding 8px 10px, font-size 12px, border-bottom 1px #F3F4F6
Last row: no border
Hover row: bg #F9FAFB
Striped: alternating bg (Filament default --striped enabled)
```

### 3.4 Layout Grid

```
Sidebar:        192px fixed, height 100vh, overflow-y auto
Topbar:         height 50px, position sticky top 0, z-index 5
Content area:   margin-left 192px (LTR) / margin-right 192px (RTL)
Content padding: 20px
```

In RTL locale, sidebar mirrors to the right and all directional padding/margin values swap.

---

## 4. Navigation & Shell Specification

### 4.1 Sidebar

#### Header Block
```
Padding: 14px 12px
Border-bottom: 1px solid #E5E7EB
Contents:
  - Brand icon (26×26px, radius 7px, bg #534AB7, white hex icon ⬡)
  - Fund name (12px, 600, #111827)
  - "Admin portal" label (10px, #9CA3AF)
```

#### Navigation Groups

**OPERATIONS** group:
| Icon | Label (EN) | Label (AR) | Badge | Action |
|------|-----------|-----------|-------|--------|
| 📊 | Dashboard | لوحة التحكم | — | Navigate |
| 👥 | Members | الأعضاء | amber: pending applications count | Navigate |
| 💰 | Loans | القروض | red: queue count | Navigate |
| 📅 | Collections | التحصيل | amber: overdue count | Navigate |
| 💳 | Disbursements | الصرف | — | Navigate |

**FINANCE** group:
| Icon | Label (EN) | Label (AR) | Badge | Action |
|------|-----------|-----------|-------|--------|
| 🔄 | Reconciliation | التسوية | red: open exception count | Navigate |
| 🏦 | Bank clearing | المقاصة البنكية | amber: unmatched count | Navigate |
| 📈 | Reports | التقارير | — | Navigate |

**SYSTEM** group:
| Icon | Label (EN) | Label (AR) | Badge | Action |
|------|-----------|-----------|-------|--------|
| ⚙️ | Settings | الإعدادات | — | Navigate |
| 📋 | Audit & System | المراجعة والنظام | — | Navigate |

#### User Block (bottom)
```
Border-top: 1px solid #E5E7EB
Padding: 10px 8px
Contents:
  - Avatar circle (26px, bg #EEEDFE, initials in #3C3489, 11px 600)
  - Name (11px, 600)
  - Role (10px, #9CA3AF)
  - Hover bg: #F9FAFB, cursor pointer
  - Click: opens profile / logout menu
```

### 4.2 Topbar

```
Height: 50px
Background: white
Border-bottom: 1px solid #E5E7EB
Z-index: 5 (sticky)
Content (left to right):
  - Page title (14px, 600, flex-1)
  - Cycle chip: "📅 Cycle: MMM YYYY" (10px, #185FA5, bg #E6F1FB, radius 6px, 3px 8px) — links to Collections
  - Notification bell button (btn-gray) with unread badge
  - Language switcher (EN/AR toggle — existing component)
  - Context primary action button (btn-primary, label changes per page)
  - Export button (btn-gray, shown on list pages only)
```

**Context primary actions by page:**

| Page | Primary action label (EN) | Label (AR) |
|------|--------------------------|-----------|
| Dashboard | Run batch | تشغيل الدُفعة |
| Members | Add member | إضافة عضو |
| Loans | Process next | معالجة التالي |
| Collections | Run debit batch | تشغيل دُفعة الخصم |
| Disbursements | Post tranche | ترحيل دفعة |
| Reconciliation | Run batch now | تشغيل المطابقة الآن |
| Bank clearing | Import statement | استيراد كشف الحساب |
| Reports | Generate | إنشاء |
| Settings | Save all | حفظ الكل |
| Audit & System | Export log | تصدير السجل |

---

## 5. Dashboard Specification

### 5.1 Page Header

Title: **Dashboard** / **لوحة التحكم**

No breadcrumb (root page).

### 5.2 KPI Strip — Row 1

Four equal-width stat cards. Data from `TenantDashboardService::snapshot()`.

| Card | EN Label | AR Label | Value | Sub-label |
|------|---------|---------|-------|-----------|
| Total members | أعضاء | member count | "+N this cycle" (green if +, gray if 0) |
| Collected this cycle | تحصيل الدورة | collection % | "X of Y members" (gray) |
| Active loans | قروض نشطة | active loan count | "N in queue" (amber if > 0, green if 0) |
| Recon exceptions | استثناءات التسوية | open exception count | "N critical, N high" (red if critical > 0) |

Stat cards are clickable: members → Members page, loans → Loans page, recon → Reconciliation page.

### 5.3 Loan Queue Panel — Row 2 Left (60% width)

Title: **Loan queue — top requests** / **قائمة انتظار القروض**  
Link: "View all →" → Loans queue page

Table (4 rows max):

| Column | EN | AR | Notes |
|--------|----|----|-------|
| # | # | # | Queue position |
| Member | العضو | — | Name only |
| Amount | المبلغ | — | SAR X,XXX (or X,XXX ر.س in AR) |
| Type | النوع | — | Chip: Emergency (red) / Standard (blue) |
| Action | — | — | [Review] btn-primary |

[Review] opens the loan review page directly.

### 5.4 Reconciliation Alerts Panel — Row 2 Right (40% width)

Title: **Reconciliation alerts** / **تنبيهات التسوية**  
Link: "Open queue →" → Reconciliation page

Contents: List of open exceptions as `notice` components:
- Critical (`notice-red`): ⛔ + exception description
- High (`notice-amber`): ⚠ + exception description
- Info (`notice-blue`): ℹ + auto-resolved info

Empty state: `notice-green` "✅ All clear — no open exceptions"

### 5.5 Collection Progress Panel — Row 3 Left

Title: **Cycle collection progress** / **تقدم تحصيل الدورة**

Progress bars (label / bar / percentage):
- Collected (green)
- Partial (amber)
- Overdue (red)
- Exempt (gray)

Below the bars, a breakdown table:
| Late tier | EN label | AR label | Count |
|-----------|---------|---------|-------|
| Tier 1 | Late Tier 1 (day 3+) | متأخر - المستوى 1 | N members |
| Tier 2 | Late Tier 2 (day 10+) | متأخر - المستوى 2 | N members |
| Tier 3 | Late Tier 3 (day 20+) | متأخر - المستوى 3 | **N — flagged** (red) |

### 5.6 Recent Activity Panel — Row 3 Centre

Title: **Recent member activity** / **النشاط الأخير للأعضاء**  
Link: "All →" → Audit log

Feed of 8 most recent admin-relevant events. Each entry:
```
[Avatar: member initials, coloured] [Member name — event description] [status chip]
```

Event types with chips:
- Contribution collected → chip-green "OK"
- Overdue flag → chip-amber "T1/T2/T3"
- Migration cleared → chip-purple "Mig"
- Loan disbursed → chip-blue "Loan"
- Loan applied → chip-purple "Apply"
- Suspension → chip-red "Susp"
- Admin override → chip-amber "Override"

### 5.7 Fund Tier Utilisation Panel — Row 3 Right

Title: **Fund tier utilisation** / **استخدام شرائح الصندوق**

Progress bars per tier:
- Tier A (1K–10K)
- Tier B (11K–30K)
- Tier C (31K–60K)
- Tier E (Emergency)

Colour logic: < 70% = green, 70–89% = amber, ≥ 90% = red

Warning notice below bars if any tier ≥ 90% capacity.

### 5.8 Quick Actions Bar

Located between Row 1 and Row 2 (compact, single row):

```
[ ▶ Run debit batch ]  [ + Add member ]  [ ⬆ Import bank statement ]  [ 📊 Generate report ]
```

All `btn-gray` style. No large gradient tiles.

---

## 6. Members Module

### 6.1 Members List

**URL:** `/admin/members`  
**Page title:** Members / الأعضاء

#### Toolbar
- Search input (placeholder: "Search by name, ID, or status…" / "ابحث بالاسم أو الرقم أو الحالة…")
- [+ Add member] btn-primary

#### Filter Pills (below toolbar)
- All (N) — primary active
- Active (N) — green outline
- Migration pending (N) — purple outline
- Delinquent (N) — red outline
- Suspended (N) — gray outline

#### Table Columns

| Column | AR label | Type | Notes |
|--------|---------|------|-------|
| ID | الرقم | text | #XXXX |
| Name | الاسم | text | Clickable → member detail |
| Status | الحالة | chip | Active=green, Overdue=amber, Migration=purple, Delinquent=red, Suspended=gray |
| Cash balance | رصيد النقد | money | SAR / ر.س formatted |
| Fund balance | رصيد الصندوق | money | SAR / ر.س formatted |
| Active loans | القروض النشطة | number | 0 gray, >0 blue badge |
| Actions | — | group | View (primary) + Edit (gray) + [Suspend/Reinstate] (danger/success) |

#### Filters (sidebar filter panel)

- Status multi-select
- Date joined range
- Fund balance range
- Has active loans (ternary)
- Has overdue contributions (ternary)
- Migration status

### 6.2 Add Member

**URL:** `/admin/members/create`  
**Page title:** Add new member / إضافة عضو جديد

2-column form grid:
- Full name / الاسم الكامل
- National ID / Iqama / رقم الهوية الوطنية / الإقامة
- Phone / الجوال
- Email / البريد الإلكتروني
- Join date / تاريخ الانضمام
- Migration cutoff date (optional) / تاريخ قطع الترحيل (اختياري)

Opening balances section (collapsible, labeled "Opening balances — leave blank for new members"):
- Cash account opening balance (SAR)
- Fund account opening balance (SAR)

Actions: [Cancel] [✓ Save member]

### 6.3 Member Detail

**URL:** `/admin/members/{id}/edit`  
**Page title:** [Member name] / [اسم العضو]

Breadcrumb: ← Members / [Name]

Action buttons (top right):
- [Suspend] btn-danger (or [Reinstate] btn-success if suspended)
- [Admin override] btn-gray
- [Edit profile] btn-primary

#### Tab: Profile

Left panel — **Member details** (2-col detail grid):
- Full name / Join date / Status (chip) / Phone / Email / Tenure / Guarantor for (if any)

Right panel — **Activity timeline** (scrollable):
- Chronological list of events (contribution, EMI, deposit, late fee, etc.)
- Each entry: bold title + sub-label (amount · date)
- AR locale: RTL text flow, EN for amounts

#### Tab: Accounts

Left panel — **Cash account**:
- Balance (large 18px) / Pending clearance / Last deposit / Last debit
- [Post manual entry] btn-primary in panel head

Right panel — **Fund account**:
- Balance (large 18px) / Loan multiplier / Max borrow limit / Total contributions / Opening balance

#### Tab: Loans

Active loan panel (if exists):
- Loan ID / Approved amount / EMI / Repayment threshold / Repaid to date / Fund tier / Guarantor
- Actions: [View schedule] [Partial settle] [Full settle]

Past loans table (if any).

No active loan: notice-blue "This member has no active loans."

#### Tab: Cycle history

Table:
| Cycle | Due | Status chip | Collected | Fee applied |
|-------|-----|------------|-----------|------------|

#### Tab: Transactions

Full ledger table:
| Date | Description | Amount (coloured: + green / - red) | Type chip (CR/DR) |

---

## 7. Loans Module

### 7.1 Loans List (Portfolio)

**URL:** `/admin/loans`  
**Cluster sub-nav:** Loans / Loan queue / Loan tiers / Fund tiers

#### Compact KPI Section (collapsible)

- Active loans count
- Total disbursed (SAR)
- In repayment (SAR outstanding)
- Delinquent count (red)

#### Filter Pills
- All / Active / Pending queue / In repayment / Settled / Delinquent

#### Table Columns
| Column | AR | Notes |
|--------|-----|-------|
| Loan ID | رقم القرض | #L-XXXX |
| Member | العضو | Clickable |
| Approved | المبلغ المعتمد | Money |
| Disbursed | المصروف | Money |
| Outstanding | المتبقي | Money |
| EMI | القسط | SAR/cycle |
| Fund tier | الشريحة | chip-blue |
| Status | الحالة | chip |
| Actions | — | View / Edit |

### 7.2 Loan Queue

**URL:** `/admin/loans/queue`

#### Filter Pills
- All (N) / Emergency (N) / Standard (N) / Pending approval / Partially disbursed

#### Table Columns
| Column | AR | Notes |
|--------|-----|-------|
| # | # | Queue position (priority-sorted) |
| Member | العضو | |
| Amount | المبلغ | Money |
| Fund tier | الشريحة | chip |
| Available in tier | المتاح | green if ≥ request, red if < |
| Type | النوع | chip-red Emergency / chip-blue Standard |
| Priority score | درجة الأولوية | Computed numeric |
| Actions | — | [Approve] btn-success + [Partial] btn-gray + [Reject] btn-danger |

### 7.3 Loan Review

**URL:** `/admin/loans/{id}`  
**Page title:** Review loan request — [Member name]

Breadcrumb: ← Loan queue / [Member]

2-column layout:

Left — **Request details** (detail grid):
- Requested amount / Type chip / Fund tier / Tier available / Member portion / Master portion / Threshold / Guarantor / Grace period / EMI tier

Right — **Eligibility checks** (check rows):
- ✅ / ❌ Tenure check
- ✅ / ❌ Fund balance check
- ✅ / ❌ No delinquency
- ✅ / ❌ Active loans within limit
- ✅ / ❌ Borrow limit within range
- ✅ / ❌ Guarantor valid and active

check-pass: bg #E1F5EE, border #1D9E75  
check-fail: bg #FCEBEB, border #E24B4A

Below — **Admin decision** panel:
- Disbursement amount input
- Decision dropdown (Full / Partial / Reject)
- Admin notes textarea (mandatory for Reject)
- Actions: [Cancel] [Reject request] [✓ Confirm disbursement]

### 7.4 Loan Tiers & Fund Tiers

Keep existing resource pages with visual cleanup to match new design tokens.

---

## 8. Collections Module

### 8.1 Collections Page

**URL:** `/admin/collections` (rename from `/admin/contributions`)  
**Page title:** Collections / التحصيل

#### KPI Strip
- Due (total members this cycle)
- Collected (count + SAR total)
- Overdue (count + SAR — red)
- Exempt (count — gray)

#### Cycle Selector

Filter pills at top:
- [Current cycle: Jun 2026] (active, purple)
- [May 2026]
- [Apr 2026]
- [All cycles]

#### Table

Header action: [▶ Run debit batch] btn-success

| Column | AR | Notes |
|--------|-----|-------|
| Member | العضو | |
| Due | المستحق | SAR |
| Status | الحالة | Collected=green, Overdue=amber+row tint, Partial=blue, Exempt=gray |
| Collected | المُحصَّل | SAR |
| Days late | أيام التأخير | Red if > 0 |
| Fee tier | مستوى الغرامة | Tier 1=amber, 2=orange, 3=red |
| Actions | — | [Post manual] if overdue; [View] otherwise |

Row colour coding for overdue:
- T1 (day 3–9): amber left border accent
- T2 (day 10–19): orange left border accent
- T3 (day 20+): red left border accent + bold name

---

## 9. Disbursements Module

### 9.1 Disbursements Page

**URL:** `/admin/disbursements`  
**Page title:** Disbursements / الصرف

#### KPI Strip
- Pending disbursement (count)
- Total committed (SAR)
- Total disbursed this cycle (SAR)
- Awaiting bank match (count)

#### Filter Pills
- All / Pending / Partial / Fully disbursed

#### Table

| Column | AR | Notes |
|--------|-----|-------|
| Member / Loan | العضو / القرض | |
| Approved | المعتمد | SAR |
| Disbursed | المصروف | SAR |
| Remaining | المتبقي | SAR (amber if > 0) |
| Status | الحالة | Active=green, Partial=amber, Pending=blue |
| Actions | — | [View loan] if done; [Disburse] btn-primary if pending |

### 9.2 Post Tranche Modal

**Trigger:** [Disburse] button on table row  
Opens modal (not a new page):

Fields:
- Loan ID (readonly)
- Member (readonly)
- Approved amount (readonly)
- Previously disbursed (readonly)
- Tranche amount (editable) — validates ≤ remaining
- Fund tier (readonly)
- Notes (optional textarea)

Actions: [Cancel] [✓ Post disbursement]

Notice at top: `notice-blue` "Loan activates and repayment schedule is built only after full approved amount is disbursed."

---

## 10. Reconciliation Module

### 10.1 Reconciliation Page

**URL:** `/admin/reconciliation`  
**Page title:** Reconciliation / التسوية

#### KPI Strip
- Open exceptions (red if > 0)
- Auto-resolved (green, last batch)
- Last batch run (time + date)
- Next batch (time + date)

#### Critical Banner (conditional)

If any critical exception open:
```notice-red
⛔ CRITICAL: [Exception description] — all batch posting is halted until resolved.
```

#### Exception Queue Panel

Header action: [▶ Run batch now] btn-primary

Table:
| Column | AR | Notes |
|--------|-----|-------|
| Type | النوع | Exception code |
| Domain | النطاق | Master account / Bank clearing / etc. |
| Severity | الخطورة | chip-red Critical / chip-amber High / chip-blue Medium |
| Delta | الفارق | SAR amount |
| SLA | المهلة | "Immediate" (red) / "Today" (amber) / "48h" |
| Actions | — | [Resolve] btn-primary |

#### Auto-resolved Panel

Table of exceptions resolved in last batch:
| Type | Domain | Resolution | Time |
| — | — | chip-green Auto | — |

#### Recon History Tab

Paginated history of all resolved exceptions with: resolved by, resolution type, correction amount, notes.

### 10.2 Resolve Exception

**URL:** `/admin/reconciliation/{id}/resolve`  
**Page title:** Resolve — [Exception type]

2-column layout:

Left — **Exception details** (detail grid):
- Exception ID / Domain / Severity chip / Delta / Raised at / Batch status (red if halted)

Right — **Resolution action**:
- Action dropdown (Post rounding / Reverse / Reclassify / Escalate)
- Correction amount input
- Reason code dropdown
- Notes textarea (mandatory for critical)
- Actions: [Cancel] [✓ Post resolution & resume batch]

---

## 11. Bank Clearing Module

### 11.1 Bank Clearing Page

**URL:** `/admin/bank` (rename from `/admin/bank-accounts`)  
**Page title:** Bank clearing / المقاصة البنكية

#### KPI Strip
- Imported today (bank line count)
- Auto-matched (count + SAR — green)
- Unmatched (count — red if > 0)
- Stale pending >30d (count — amber)

#### Tabs

**Unmatched** tab (default — shows action required):  
Table: Date / Reference / Amount / Exception chip / Actions (Match manually / Adjust & clear)

**Matched today** tab:  
Table: Bank line / Matched to / Amount / Status chip-green Cleared

**Import history** tab:  
Table of import sessions with line counts and match rates.

**SMS channel** tab:  
Embedded SMS import session management (existing workspace).

#### Import Action
Header action: [⬆ Import statement] btn-primary — opens upload modal.

---

## 12. Reports Module

### 12.1 Reports Page

**URL:** `/admin/reports`  
**Page title:** Reports / التقارير

2-column layout:

#### Standard Reports (left)

Each report as a card:
```
[Icon box] [Report title + description] [⬇ PDF] [⬇ Excel]
```

Reports:
1. Monthly collection report — per-member collection status, fees, exemptions
2. Loan portfolio report — active, repaid, delinquent — all tiers
3. Reconciliation summary — exceptions, resolutions, auto-resolve log
4. Fund tier utilisation — availability, committed, disbursed per tier
5. Member statements (bulk) — generate all member statements for a cycle
6. Guarantor exposure report — guaranteed loans per guarantor, risk flags
7. Audit trail export — full admin action log for a date range

#### Custom Report Builder (right)

Fields:
- Report type (dropdown)
- Date from / Date to
- Member (optional search)
- Fund tier (optional multi-select)
- Status filter (optional)
- Format: PDF / Excel / CSV

[⬇ Generate report] btn-primary full width

---

## 13. Settings & Configuration Module

### 13.1 Settings Page

**URL:** `/admin/settings`  
**Page title:** Settings / الإعدادات

#### Tab: General
- Fund name
- Cycle day of month
- Bank account details (IBAN, account name)
- Fund logo upload
- Contact email / phone

#### Tab: Collection
- Collection window (days)
- Late fee model (Replacement / Cumulative)
- Tier 1 threshold + fee amount
- Tier 2 threshold + fee amount
- Tier 3 threshold + fee amount

#### Tab: Loans
- Minimum membership years
- Minimum fund balance (SAR)
- Borrow multiplier (×)
- Maximum active loans per member
- Settlement threshold (%)
- Grace period options

#### Tab: Fund tiers
- Tier A allocation (%) + range (SAR)
- Tier B allocation (%) + range (SAR)
- Tier C allocation (%) + range (SAR)
- Tier E emergency allocation (%)

#### Tab: Reconciliation
- Auto-resolve tolerance (SAR)
- Timing difference defer (hours)
- Bank match date range (± days)
- Stale pending threshold (days)
- Unbanked cash alert (days)

#### Tab: Guarantor rules
- Missed EMIs before warning (X)
- Missed EMIs before guarantee transfer (Y)

#### Tab: Notifications
- Per-event-type toggles: SMS / Email / In-app
- SMS template editor per event
- Email template editor per event

Each tab has a [Save changes] btn-primary.

---

## 14. Audit & System Module

### 14.1 Audit & System Page

**URL:** `/admin/audit`  
**Page title:** Audit & System / المراجعة والنظام

#### Tab: Audit log

Filter pills: All events / Admin actions / Overrides / Recon events / Loan events

Table:
| Timestamp | Event | Actor | Entity | Tag chip |
| — | — | Admin/System | #ID or Global | chip coloured |

Export action in header.

#### Tab: Notification log

Table: Member / Channel / Event / Status chip (Delivered/Failed/Pending) / Sent at / Delivery time

#### Tab: Jobs & commands

Table of scheduled jobs with last run, next run, status, [Run now] action.

#### Tab: System maintenance

Database backup overview widget + DB backup table + purge controls.

#### Tab: Legacy migration

Existing legacy migration workflow (CSV import, classify, resolve stubs).

#### Tab: Year-end close

Existing fiscal year close workflow.

---

## 15. Bilingual Specification

### 15.1 Locale Switching

- Language switcher in topbar (existing `language-switcher` component)
- Locale stored in admin user session
- `SetApplicationLocale` middleware applies `app()->setLocale()` on each request
- `<html dir="rtl" lang="ar">` applied when locale = `ar`

### 15.2 String Keys Convention

Namespaced keys in `lang/ar.json` and `lang/en.json`:

```
admin.nav.{item}
admin.dashboard.kpi.{stat}
admin.dashboard.{panel}.title
admin.members.{label}
admin.loans.{label}
admin.collections.{label}
admin.disbursements.{label}
admin.recon.{label}
admin.bank.{label}
admin.reports.{label}
admin.settings.{tab}.{label}
admin.audit.{label}
```

### 15.3 Money Formatting

| | English (LTR) | Arabic (RTL) |
|--|--------------|-------------|
| Full | `SAR 1,234.50` | `1,234.50 ر.س` |
| Compact | `SAR 1.2K` | `1.2K ر.س` |
| Chip | `SAR` | `ر.س` |
| Digits | `1,234` | `1,234` (Western only) |

Currency symbol placement flips with text direction. Implemented in `MoneyDisplay::format()` and `MoneyDisplay::compact()`.

### 15.4 RTL Layout Rules

- Sidebar: `right: 0` in RTL (mirrors to right)
- Content: `margin-right: 192px` in RTL
- Table: text-align follows content type — amounts always left-aligned even in RTL (preserve readability of columns)
- Icons: directional icons (arrows, chevrons) flip with `transform: scaleX(-1)` in RTL context
- Breadcrumbs: arrow separator flips direction

### 15.5 Arabic Typography in PDFs

- Font: `Amiri` or `Noto Naskh Arabic` for body, `Scheherazade New` for headings
- `DomPdfFactory` already handles Arabic shaping
- All PDF templates output `<span lang="ar" dir="rtl">` for Arabic strings
- Tables in PDFs: English column headers even in AR locale for readability (open for review)

### 15.6 Status Chips & Enum Labels

| Status | EN chip | AR chip |
|--------|---------|---------|
| Active | Active | نشط |
| Overdue | Overdue | متأخر |
| Delinquent | Delinquent | متعثر |
| Suspended | Suspended | موقوف |
| Collected | Collected | مُحصَّل |
| Exempt | Exempt | معفى |
| Partial | Partial | جزئي |
| Pending | Pending | قيد الانتظار |
| Cleared | Cleared | مُسوَّى |
| Critical | Critical | حرج |

---

## 16. Output Documents Specification

### 16.1 Monthly Statement (member-facing PDF)

- Existing `StatementPdfController` + `monthly-statement.blade.php`
- EN: Header "Monthly Statement — [Month Year]", "SAR" currency symbol
- AR: Header "كشف الحساب الشهري — [Month Year]", "ر.س" currency symbol, RTL layout
- All numbers in Western digits
- Fund name in both EN and AR in header
- Member name in native script (Arabic if Arabic name)

### 16.2 Loan Schedule PDF (new)

- `LoanSchedulePdfController` (new, already exists on branch)
- Repayment schedule table: Cycle / Due date / Principal / EMI / Outstanding
- Bilingual: EN and AR versions available via `?lang=ar` parameter
- All amounts in SAR / ر.س per locale

### 16.3 Collection Report PDF (admin-facing)

- Generated from Reports page
- Per-member table: Member ID, Name, Due, Collected, Status, Late fee
- Summary row: Total due, Total collected, Collection rate %
- Admin generates from Reports page; available in PDF and Excel

### 16.4 Audit Trail Export

- CSV export from Audit log tab
- Columns: Timestamp, Event, Actor, Entity ID, Tag, Notes
- Always in English (audit trail is system-facing)

### 16.5 Member Notifications (in-app + SMS + email)

All outbound notifications are bilingual:
- Member's preferred language (stored in member profile)
- Fallback: fund's default locale
- SMS: short-form with `ر.س` for AR, `SAR` for EN
- Email: HTML template with full formatting
- In-app: title + body, locale-aware

---

## 17. Missing Feature Specifications

### 17.1 Fund Pool Health Panel

**Location:** Dashboard, between KPI strip and row 2 (or as a collapsible section)

Display:
```
Master fund:   SAR 2,450,000  ━━━━━━━━━━━━━━━━━  ✅ Balanced
Member funds:  SAR 2,450,000

Master cash:   SAR 180,000    ━━━━━━━━━━━━━━━━━  ✅ Balanced
Member cash:   SAR 180,000

Solvency:      142%           ▓▓▓▓▓▓▓▓░░░░  (loan commitments vs fund)
30-day drift:  ± SAR 0.00    ✅ No drift detected
```

Links to Reconciliation if any drift detected.

### 17.2 Bulk Member Announcement

**Location:** Messages inbox page → [Compose announcement] action

Form:
- Recipients: All active / Overdue / Delinquent / With active loans / Selected
- Message title (EN) / عنوان الرسالة (AR)
- Message body (EN) / نص الرسالة (AR)
- Delivery channels: In-app ✓ / SMS / Email
- Schedule: Send now / Schedule for date+time

Preview section shows rendered message in both locales.

Confirmation: "This will send to N members. Confirm?"

### 17.3 Guarantor Exposure View

**Location:** Member detail → new "Guarantor" tab (shown only if member is a guarantor for any loan)

Table: Loan ID / Borrower / Approved amount / Outstanding / Status / Exposure risk

Summary: Total guaranteed SAR / Max single exposure SAR / Risk flag (red if any guaranteed loan is delinquent)

Also available as a report (§12.1 item 6).

### 17.4 EMI Collection Calendar

**Location:** Loans module → [Collection calendar] action in header

Month grid view:
- Each cell = a day in the cycle month
- Dots on days where EMIs are due
- Colour: green (collected), amber (pending), red (overdue)
- Click a day: slide-out showing which members' EMIs are due that day

### 17.5 Support Ticket Workflow

**Location:** Members module → [Support] relation tab; Messages inbox → [Support requests] filter

Per request:
- Status: Open / In progress / Resolved / Closed (admin changes)
- Admin reply: textarea + [Send reply] — creates message to member
- SLA indicator: days open (green <3, amber 3–7, red >7)
- Escalate flag: marks request for supervisor attention

### 17.6 Loan Queue Priority Score

**Algorithm:**

```
priority_score = base_type_score + tenure_bonus + days_in_queue_bonus + standing_bonus

base_type_score:  Emergency = 100, Standard = 50
tenure_bonus:     min(member_years × 2, 20)          max 20 points
days_in_queue:    min(days_waiting × 1.5, 30)         max 30 points
standing_bonus:   clean_record ? 10 : 0               10 points if no delinquency
```

Score shown as numeric in queue table. Queue sorted by score descending by default.
