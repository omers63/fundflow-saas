# Member Portal Redesign — Analysis & Plan

This is the **master analysis document**. Detailed deliverables:

| Document | Audience | Contents |
|----------|----------|----------|
| **[Product specification](member-portal-specification.md)** | Product, QA, stakeholders | Functional requirements (FR-*), NFRs, acceptance criteria, scope |
| **[Design & implementation plan](member-portal-implementation-plan.md)** | Engineering | Architecture, phases, files, tests, git workflow, DoD |

This is a planning document only. No implementation is proposed yet.

**When implementation starts:** all work happens on a dedicated git branch (`feature/member-portal-redesign`), branched from `main`. See [implementation plan §1.2](member-portal-implementation-plan.md#12-git-workflow).

**Related assets:**
- Blueprint prototype: [`docs/Claude/member-portal-prototype.html`](Claude/member-portal-prototype.html)
- Current portal doc: [`docs/member-portal.md`](member-portal.md)

---

## 1. Executive vision

**Goal:** Turn the member portal from a **15-item Filament sidebar with 11 stacked dashboard sections** into a **prototype-style hub**: one calm Overview where members see what matters and act immediately, with deeper pages reached only when needed.

**Design north star:** Match the tone of `docs/Claude/member-portal-prototype.html` — compact panels, soft color accents, clear chips, contextual notices — while keeping every real FundFlow capability (household, guarantor loans, eligibility overrides, bilingual/RTL, PWA).

**Principle:** *Dashboard = command center. Sidebar = wayfinding, not a feature dump.*

---

## 2. Current state — what's wrong

### 2.1 Information architecture

| Issue | Evidence |
|-------|----------|
| **Finance fragmented 6 ways** | Contributions, Deposits, Cash-outs, Statements, Accounts, Dependents — each with its own nav item and duplicate insights hero |
| **Loans split 3 ways** | My Loans, Guaranteed Loans, Loan Calculator — plus a Loans tab inside My Accounts |
| **Two "talk to admin" paths** | Messages (ungrouped) vs Support & requests (Settings) — same icon family, overlapping intent |
| **Dashboard does everything twice** | `MemberPortalDashboardWidget` already shows KPIs, cycle, loan card, arrears, recents, household — then list pages repeat similar heroes |
| **QA in production nav** | `BusinessDayTestingPage` in Settings |

### 2.2 Dashboard cognitive load

Current scroll order (`member-portal-dashboard.blade.php`):

1. Greeting hero
2. 7 gradient quick-action tiles
3. Lifecycle journey steps
4. 8 KPIs + sparkline
5. Arrears block
6. Relation summaries (mini cards linking to every area)
7. Cycle + loan + eligibility card
8. Trend + activity + quick links
9. Recent contributions + deposits
10. Latest statement
11. Household

That is **comprehensive but not simple**. Members must scan ~2–3 screens on mobile before seeing "what do I do next?"

### 2.3 Visual / UX friction

- **Emerald Filament default** + heavy custom CSS (`!important` font overrides) fights the panel chrome instead of a cohesive product skin.
- **Gradient action tiles** (7 across on XL) feel busy vs prototype's quiet **quick-action rows** with icon badges.
- **Insights duplicated** on 8+ list pages via separate Blade partials — hard to restyle globally.
- **No contextual top notice** (prototype's amber EMI banner) — urgency is buried in arrears/KPIs.

### 2.4 What works and must be preserved

- `MemberPortalInsightsService` + sibling `*InsightsService` classes — solid data layer.
- `CurrentMember` scoping, policies, impersonation.
- `MemberNavigation` sort constants — good hook for IA changes.
- Mobile-first shared CSS (`mobile-panels.css`).
- Bilingual strings (`translateLabel()`, `lang/ar.json`).
- Filament resources for CRUD-heavy flows (deposits, cash-out, loan view).

---

## 3. Prototype blueprint — design language to adopt

The prototype (`docs/Claude/member-portal-prototype.html`) defines a **member product UI**, not admin Filament chrome.

### 3.1 Color system (adopt as tokens)

| Token | Use | Hex (prototype) |
|-------|-----|-----------------|
| **Primary** | CTAs, active nav, fund-adjacent accents | `#534AB7` / `#3C3489` |
| **Success** | Credits, collected, active status | `#1D9E75` |
| **Warning** | Due soon, reserved EMI, amber notices | `#EF9F27` |
| **Danger** | Debits, arrears, missed cycles | `#E24B4A` |
| **Info** | Informational notices | `#378ADD` |
| **Neutrals** | Panels, borders, labels | gray-50 → gray-900 |

Map these to Filament `Color::hex()` or CSS variables in `resources/css/filament/member/theme.css` — **replace or soften Emerald** as primary.

### 3.2 Layout patterns

| Pattern | Prototype | Apply to FundFlow |
|---------|-----------|-------------------|
| **Sidebar profile block** | Avatar initials, member #, since date, status chip | Move from user-menu-only profile into collapsible sidebar header |
| **Sticky page topbar** | Title + contextual action (Statement, Export, etc.) | Filament topbar: page title + one primary action |
| **Panel cards** | `border-radius: 14px`, head/body split, "Details →" links | Replace mixed insight partials with one `ff-panel` component |
| **Notice banners** | Amber/blue/green/red contextual strips | Single `ff-notice` driven by priority rules (EMI due > arrears > info) |
| **Quick actions** | Icon square + title + subtitle rows | Replace 7 gradient tiles with 4–6 rows |
| **Chip status** | green/amber/red/blue/purple/gray pills | Unify loan/contribution/txn status rendering |
| **Tab sub-nav** | Loans: Active / History / Settle | Consolidate loan surfaces under one resource |
| **Filter chips** | Transactions page | Reuse on unified activity feed |
| **Fund hero** | Purple gradient card | Distinct visual identity for fund (not cash) |

### 3.3 Typography & density

- Base **13px**, labels **10–11px uppercase**, amounts **22–34px bold**.
- Tighter than default Filament — aligns with existing `ExtraSmall` table defaults but needs **consistent panel spacing**, not per-widget overrides.

---

## 4. Target information architecture

### 4.1 Proposed sidebar (8–10 items vs 15)

```
┌─ Profile block (avatar, #, status) ─────────┐
│ Overview                          [home]    │
├─ My accounts ──────────────────────────────┤
│   Cash account                               │
│   Fund account                               │
├─ Loans ──────────────────────────────────────┤
│   My loans                    [badge]        │
│   Request a loan                               │
│   Guaranteed loans            [conditional]  │
├─ History ────────────────────────────────────┤
│   Contributions                              │
│   Activity (transactions)                    │
├─ Self-service ───────────────────────────────┤
│   Cash out                                   │
│   Statements                                 │
├─ Help ───────────────────────────────────────┤
│   Communications & FAQ         [unread badge]│
│   (Messages · Requests · Alert history)      │
├─ Settings ───────────────────────────────────┤
│   Profile & preferences  (merged page)       │
└─ Sign out ───────────────────────────────────┘
```

| Current item | New home |
|--------------|----------|
| My Deposits | Cash account → Deposit panel + dashboard quick action |
| My Accounts (monolith) | Split into Cash / Fund focused pages |
| My Cash-Outs | Cash out page (request + history) |
| Loan calculator | Link inside Request a loan + loan detail |
| Contribution settings | Settings → Profile & preferences tab |
| Notification preferences | Settings tab |
| Support & requests | Help → Communications (Requests tab) |
| Messages (ungrouped) | Help → Communications (Messages tab); optional sidebar badge |
| Business calendar testing | **Remove from member nav** (admin/QA only) |
| My dependents | Overview household panel + Settings (parents only) |

### 4.2 Page consolidation map



![Diagram 1](../_assets//var/www/fundflow-saas/docs/member-portal-redesign-plan/diagram-01.png)



---

## 5. Dashboard redesign — "comprehensive yet simple"

Reorganize into **4 vertical zones** (prototype-aligned). Everything below fits **one mobile screen + one scroll** for typical members.

### Zone A — Context (always first)

**Single priority notice** (only one visible; ranked):

1. Delinquent / suspended status
2. EMI due within N days (with amount + link to loan)
3. Contribution due / insufficient cash for open period
4. Pending deposit or cash-out status
5. Unread admin message
6. Eligibility override pending
7. Informational (e.g. exempt from contribution during loan)

*Replaces:* separate hero + arrears + cycle warnings scattered across sections.

### Zone B — Balances (2-column grid)

| Cash panel | Fund panel (gradient) |
|------------|------------------------|
| Large balance | Large balance |
| Available vs reserved EMI | Borrow cap (×3), monthly contribution |
| Actions: Deposit, Cash out, History | Actions: History, Statement |

*Data source:* existing `MemberPortalInsightsService` balances + `LoanDelinquencyService` for reserved EMI.

*Retire from dashboard:* 8-KPI strip as a separate row (see Zone D for condensed KPIs).

### Zone C — Active obligation + quick actions (3fr / 2fr)

**Left — Active loan panel** (if any; else eligibility CTA):

- Progress bar (% to threshold, not just installment count)
- Chip row: EMIs, threshold, guarantor, next due
- Inline actions: Partial settle, Full settle → deep-link to loan settle tab

**If no active loan:** Eligibility checklist card (prototype step 1 preview) + "Request a loan".

**Right — Quick actions** (max 5 rows):

1. Deposit funds
2. Request a loan (with max eligible)
3. Cash out (with available amount)
4. Download statement
5. Messages (if unread) / Guaranteed loans (if guarantor)

*Retire:* lifecycle journey steps (redundant with loan panel + notices), relation summary grid, separate quick links block.

### Zone D — Recent activity (one panel)

Unified table (last 5–8 rows):

- Description | Date | Credit | Debit | Type chip
- "All activity →" links to History → Activity with filter chips

*Merge:* `portal-trend-activity`, `portal-recent-contributions`, duplicate transaction lists.

### Conditional footer blocks (collapsed by default)

| Block | When shown |
|-------|------------|
| **Household** | Parent with dependents or separated member |
| **Guaranteed exposure** | Member is guarantor on ≥1 active loan |
| **Contribution sparkline** | Optional "Insights" expander — not default visible |

### Dashboard data contract (refactor `MemberPortalInsightsService`)

Proposed snapshot shape:

```php
[
    'notice' => ?array,           // single prioritized banner
    'cash_card' => array,
    'fund_card' => array,
    'loan_panel' => ?array,       // or eligibility_panel
    'quick_actions' => list<array>,
    'recent_activity' => list<array>,
    'expandable' => [
        'household' => ?array,
        'guarantor' => ?array,
        'contribution_trend' => ?array,
    ],
]
```

Keep heavy queries; **change presentation grouping**, not business logic.

---

## 6. Secondary pages — consolidated widgets

### 6.1 Cash account (replaces Deposits list as primary entry)

**Layout:** 2-column top + full-width history.

| Left | Right |
|------|-------|
| Balance, pending clearance, reserved EMI, last deposit/debit | Deposit form (method, amount, notes) — wraps `MyFundPostingResource::create` |

**History:** Cash ledger from `MyAccountResource` cash account transactions (not a separate nav item).

**List page fate:** `MyFundPostingResource` index becomes "Deposit history" subsection or redirect.

### 6.2 Fund account

**Layout:** Purple gradient hero (prototype) + 6-cell detail grid:

- Monthly contribution, borrow multiplier, total contributed, loan deductions, exemption status, exemption end

**History:** Fund ledger table (contributions + fund movements).

### 6.3 Loans hub (`MyLoanResource` enhanced)

**Tabs:**

| Tab | Content |
|-----|---------|
| **Active** | Expandable loan cards with schedule table (installment relation manager inline) |
| **History** | Completed/cancelled loans table |
| **Settle** | Full/partial settlement (existing `MemberLoanFilamentActions`) |
| **Apply** | Embed `ApplyForLoan` wizard OR link with shared stepper UI |

**Guarantor loans:** Separate nav item only when count > 0; same card language, read-only + exposure KPIs from `MemberGuaranteedLoanInsightsService`.

**Loan calculator:** Modal or sidebar drawer from Request flow — not standalone nav.

### 6.4 Contributions

**Top:** 4 stat cards (prototype): total contributed, this cycle, cycles missed, cycles exempt.

**Body:** Contribution history table with status chips.

**Remove:** Full insights hero duplicate of dashboard fund/cycle data.

### 6.5 Activity (new unified page)

Merge cash + fund + loan event streams with filter chips:

`All | Contributions | EMI | Deposits | Late fees | Loan events | Cash outs`

*Implementation:* New `MemberActivityPage` querying existing transaction/repayment models (same data as My Accounts "All" tab).

### 6.6 Cash out

Prototype layout: request form left, history right.

Surface **available = balance − reserved EMI** prominently (currently missing from dashboard).

### 6.7 Statements

Monthly statement list + **download center** (activity CSV, loan schedule PDF) — see **§16.3**. Keep PDF route `tenant.member.statement.pdf`.

### 6.8 Settings (single page, tabs)

| Tab | Merges |
|-----|--------|
| Profile | `MyProfilePage` + `EditMyProfilePage` |
| Contributions | `MyContributionSettingsPage` + dependent allocations |
| Notifications | `MyNotificationPreferencesPage` |
| Security | Password change (if exposed) |
| Payout bank | Read-only cash-out destination; contact support to change (§16.6) |

### 6.9 Help & communications

Single **Help** nav item — see **§16.2**:

| Tab | Content |
|-----|---------|
| Messages | `MyMessageResource` embedded |
| Requests | Support tickets + household member requests |
| Alert history | Read-only `NotificationLog` for current user |
| FAQ | Accordion — prototype FAQs in lang files; tenant override later (§16.5) |

---

## 7. Design system — components to build

Introduce a **small Blade component library** under `resources/views/components/member-portal/`:

| Component | Purpose |
|-----------|---------|
| `x-member.panel` | Head, body, optional link |
| `x-member.notice` | tone: amber/blue/green/red |
| `x-member.chip` | status variants |
| `x-member.stat-card` | label + value + footnote |
| `x-member.quick-action` | icon + title + subtitle row |
| `x-member.progress-bar` | loan repayment |
| `x-member.detail-grid` | 2-col key/value |
| `x-member.filter-chips` | activity filters |
| `x-member.tab-bar` | sub-page tabs |
| `x-member.amount` | signed color (green CR / red DR) |

**CSS:** Extend `theme.css` with `--ff-primary`, `--ff-success`, etc. Scope under `.fi-panel-member`. Retire per-widget accent classes where possible.

**Icons:** Replace emoji in prototype with Heroicons (already in Filament) — same visual weight via colored icon squares.

---

## 8. Technical architecture plan

### 8.1 Keep Filament; don't rewrite the panel

Stay on Filament v5 member panel — redesign is **presentation + IA**, not a new SPA.

| Layer | Approach |
|-------|----------|
| **Navigation** | Update `MemberNavigation` sorts; hide/re-group resources |
| **Dashboard** | Rewrite `member-portal-dashboard.blade.php` + slim `MemberPortalInsightsService` output |
| **Account pages** | New custom `CashAccountPage` / `FundAccountPage` OR refactor `MyAccountResource` into two registered pages |
| **List insights widgets** | Phase out heroes on list pages; keep thin KPI strip only where needed |
| **Services** | Add `MemberActivityFeedService`; reuse existing insights services |
| **Dead code** | Remove `MyFundOverview`, wire or delete `MemberArrearsAlert` |

### 8.2 Feature gap: prototype vs FundFlow

| Prototype feature | FundFlow status | Plan |
|-------------------|-----------------|------|
| Inline deposit form | Create via resource | Embed create form on Cash page |
| IBAN on cash out | `MemberCashOutService` | Show registered destination |
| Statement generator | Pre-generated monthly | Add date-range export if not exists; else filter existing |
| Bank details settings | May be partial | Show what member has; edit via support if not self-serve |
| FAQ | Not in app | Phase 2 — static markdown per tenant |
| Save draft (loan) | Unknown | Evaluate `ApplyForLoan` session persistence |
| Reserved EMI | Computable | Add to `MemberPortalInsightsService` |
| Threshold progress | On loan model | Show on loan panel (prototype has this) |

| FundFlow feature | Prototype | Plan |
|------------------|-----------|------|
| Household / dependents | Missing | Overview expandable + Settings |
| Impersonation | Missing | Unchanged; parent banner in topbar |
| Guaranteed loans | Missing | Conditional nav + expandable dashboard |
| Eligibility override | Missing | Notice + quick action when applicable |
| Arabic / RTL | Missing | All new components use logical properties (`ms-`, `pe-`) |
| PWA | Missing | Keep existing hooks |

### 8.3 Bilingual / RTL (summary)

Full mandatory requirements are in **§13**. At minimum: Arabic + English UI copy, RTL layout, locale-aware currency symbol (**`SAR`** in English / **official Saudi Riyal sign U+20C1** in Arabic), and **Western digits (0–9) for all numeric display**.

---

## 9. Phased implementation roadmap

### Phase 0 — Discovery & design freeze (1 week)

- [ ] Create branch `feature/member-portal-redesign` from `main`
- [ ] Sign-off on IA diagram and dashboard wireframe (Figma or HTML mock from prototype)
- [ ] Confirm Settings merge scope (password self-serve?)
- [ ] Decide: split My Accounts into pages vs tabbed single resource
- [ ] Sign-off on §16 value-add scope (communications, documents, analytics)
- [ ] Remove `BusinessDayTestingPage` from member navigation
- [ ] Update `docs/member-portal.md` to match target state

### Phase 1 — Design system foundation (1–2 weeks)

- [ ] Color tokens + `x-member.*` components
- [ ] **`member-portal-chrome.css`** — scoped Filament overrides per implementation plan §4.4 (forms, tables, buttons, sidebar, topbar)
- [ ] Refactor `insights-kpi-strip` / chips to use tokens
- [ ] Sidebar profile block in `MemberPanelProvider` render hook
- [ ] Architecture test: no raw emoji in member views (optional)
- [ ] **`x-member.amount`**: Western digits + `__('SAR')` symbol (`SAR` / U+20C1); RTL-safe credit/debit colors
- [ ] **Saudi Riyal font**: bundle/load font with U+20C1 for member theme + PDF (§13.3)
- [ ] **`lang/ar.json`**: update `"SAR": "\u20C1"`; all new strings in same PR as UI
- [ ] **`MemberLocale::using()`** helper for async notification/PDF locale (§15)
- [ ] **Visual sign-off**: screenshot checklist vs prototype (375px + 1280px) on forms/tables/chrome

### Phase 2 — Dashboard rewrite (1–2 weeks)

- [ ] Reshape `MemberPortalInsightsService::snapshot()`
- [ ] New dashboard Blade (Zones A–D)
- [ ] Priority notice builder (extract from hero + arrears + cycle)
- [ ] Feature tests: dashboard renders for active loan / no loan / delinquent / guarantor
- [ ] **Pest tests**: dashboard in `en` and `ar` locales; assert Western digits in amounts

### Phase 3 — Navigation consolidation (1 week)

- [ ] Reorder/hide nav items per §4.1
- [ ] Merge Support + Messages under Help
- [ ] Move calculator inline to loan request

### Phase 4 — Account & cash pages (2 weeks)

- [ ] Cash account page (balance + deposit + ledger)
- [ ] Fund account page (gradient hero + ledger)
- [ ] Cash out page polish (available balance)
- [ ] Redirect old deposit list → cash page

### Phase 5 — Loans hub (2 weeks)

- [ ] Tabbed loan index (active/history/settle)
- [ ] Loan card + schedule inline
- [ ] Apply wizard shared stepper styling

### Phase 6 — History & cleanup (1–2 weeks)

- [ ] Unified Activity page + filters
- [ ] Contributions page stat cards only
- [ ] Strip duplicate insights widgets from list pages
- [ ] Delete orphaned `MyFundOverview`

### Phase 7 — Settings, household & help (1 week)

- [ ] Tabbed Settings page
- [ ] Household panel on overview (parents)
- [ ] Dependent impersonation entry from household block
- [ ] Help hub shell (FAQ accordion + links to Messages / Support)

### Phase 8 — Value-add: communications, documents, analytics (1–2 weeks)

See **§16** for full scope. Deliverables:

- [ ] Communications center (Messages + Support + alert history tabs)
- [ ] Fund bank-transfer details on Cash / deposit panel
- [ ] Documents: activity CSV export + loan schedule PDF
- [ ] Dashboard expandable insights strip (simple analytics)
- [ ] Pending actions surfaced in dashboard notice zone

**Total estimate:** 10–14 weeks incremental, shippable after each phase.

---

## 10. Success metrics

| Metric | How to measure |
|--------|----------------|
| **Time to first action** | Analytics: clicks from login to deposit/loan/cash-out < 2 |
| **Nav depth** | Avg sidebar items used per session ↓ |
| **Dashboard bounce** | % sessions that only view dashboard without action (target: meaningful notices drive clicks) |
| **Mobile completion** | Deposit/cash-out form completion rate on small viewports |
| **Support tickets** | "Where is X?" category ↓ after IA change |

---

## 11. Risks & open decisions

| Decision | Options | Recommendation |
|----------|---------|----------------|
| **Primary color shift** | Keep Emerald vs adopt prototype purple | **Purple primary** for member panel only; admin stays distinct |
| **My Accounts resource** | Split vs tabs | **Two pages** (Cash, Fund) — matches prototype mental model |
| **Deposit UX** | Inline form vs modal | **Inline on Cash page** (prototype) |
| **Messages placement** | Sidebar vs Help tab | **Sidebar badge** + Help tab duplicate for discoverability |
| **Business day testing** | Delete vs admin-only | **Remove from member panel** |
| **List page widgets** | Remove all vs minimal strip | **Remove heroes**; optional 2-stat strip on Contributions only |
| **Breaking URLs** | Redirects needed | Add Filament redirects from old resource URLs for bookmarks |
| **Prototype fidelity** | Filament defaults vs HTML mock | **Phase 1 `member-portal-chrome.css`** scoped to `.fi-panel-member` (§4.4 implementation plan) |

---

## 12. Summary

The current portal is **functionally rich but architecturally noisy**: too many sidebar destinations, too many dashboard sections, and repeated insight widgets that re-show the same numbers. The prototype shows the right direction — **panel-based layout, prioritized notices, account-centric navigation, and a dashboard that answers three questions**:

1. **What needs my attention now?** (notice banner + pending actions)
2. **What are my balances and obligations?** (cash + fund + loan panels)
3. **What can I do next?** (quick actions + recent activity)

Implementation should **not** replace Filament or the insights service layer. It should introduce a **member design system**, **reshape the dashboard data contract**, and **consolidate 15 nav items into ~9 purposeful destinations** while preserving household, guarantor, eligibility override, bilingual, and PWA behavior.

**New value** (§16) fills gaps in communications history, self-serve documents, and simple analytics — without adding a second dashboard or heavy reporting.

**Git workflow:** one feature branch (`feature/member-portal-redesign`); phase PRs; bilingual + Western-digit rules (§13–15) on every PR; Arabic currency uses **official Saudi Riyal sign U+20C1** via `__('SAR')`.

---

## Appendix: Key file references

| Area | Path |
|------|------|
| Panel provider | `app/Providers/Filament/MemberPanelProvider.php` |
| Navigation constants | `app/Filament/Member/Support/MemberNavigation.php` |
| Dashboard page | `app/Filament/Member/Pages/MemberDashboard.php` |
| Dashboard widget | `app/Filament/Member/Widgets/MemberPortalDashboardWidget.php` |
| Dashboard view | `resources/views/filament/member/widgets/member-portal-dashboard.blade.php` |
| Insights service | `app/Services/MemberPortalInsightsService.php` |
| Member theme CSS | `resources/css/filament/member/theme.css` |
| Blueprint prototype | `docs/Claude/member-portal-prototype.html` |
| Bilingual UI rules | `.cursor/rules/bilingual-ui-strings.mdc` |
| Arabic typography | `resources/css/arabic-typography.css` |
| Currency formatting | `app/Filament/Support/MoneyDisplay.php` |
| Arabic currency key | `lang/ar.json` → `"SAR": "\u20C1"` (official Saudi Riyal sign) |
| Member notifications | `app/Notifications/Tenant/*` |
| Notification channels | `app/Notifications/Concerns/DeliversToMemberChannels.php` |
| User locale preference | `app/Models/Tenant/User.php` → `preferredLocale()` |
| Statement PDF | `resources/views/pdf/monthly-statement.blade.php` |
| Direct messages | `app/Services/Tenant/DirectMessagingService.php` |
| Notification prefs | `app/Services/Tenant/NotificationPreferenceService.php` |
| Notification delivery log | `app/Models/Tenant/NotificationLog.php` |
| Value-add features plan | §16 in this document |

---

## 13. Bilingual implementation requirements (mandatory)

**Stakeholder directives:**

1. Arabic/English must be first-class across **all member touchpoints** — portal UI, PDFs, emails, SMS, WhatsApp, in-app notifications, messages, validation, and exports — not a follow-up pass.
2. All numeric values use **English (Western) digits** (`0`–`9`), even when the UI or document is in Arabic.

### 13.1 Locales & switching

| Rule | Implementation |
|------|----------------|
| **Supported locales** | `en` (default), `ar` |
| **Locale switch** | Existing `LanguageSwitchComponent` in topbar; preserve on all new pages |
| **Filament labels** | `translateLabel()` on columns, fields, actions, filters, tabs — do **not** double-wrap with `__()` |
| **Blade / custom widgets** | Every user-visible string via `__()` or `trans()` |
| **Interpolation** | `__('EMI of :amount due in :days days', ['amount' => $fmt, 'days' => $n])` — never concatenate Arabic/English fragments |
| **Missing keys** | Add to `lang/ar.json` in the **same PR** as the UI change |
| **User locale** | `User::preferredLocale()` persisted on language switch (`LocalizationServiceProvider`) |
| **Async / queued sends** | Set `app()->setLocale($user->preferredLocale())` before building notification/PDF/email body (see §14.2) |
| **Do not translate** | Internal statuses, log messages, CSS classes, enum keys stored in DB |

### 13.2 Numbers — always Western digits

Arabic locale must **not** render Arabic-Indic numerals (٠١٢٣…) anywhere in the member portal.

| Display type | Rule | Example (Arabic UI) |
|--------------|------|---------------------|
| **Money amounts** | Format with `locale: 'en'` (or `number_format`) | `⃁ 3,240.00` not Arabic-Indic digits |
| **Percentages** | Western digits + `%` | `33%` |
| **Counts** | Western digits | `5 of 18` → translate words, keep digits |
| **Member / loan IDs** | Western digits | `#1047`, `#L-0034` |
| **Phone / IBAN** | Western digits + Latin letters for IBAN | `SA44 0000…` |
| **Dates — day/year** | Western digits | `1 Jun 2026` |
| **Dates — month names** | Localized words OK | `يونيو` or `Jun` via `translatedFormat()` |
| **Form inputs** | `inputmode="decimal"` / `type="number"` | User types `1500` |
| **Sparklines / charts** | Axis labels in Western digits | |

**Technical fix (implementation):** Extend `MoneyDisplay::format()` (and new `x-member.amount`) to use `Number::format(..., locale: 'en')` for the numeric portion while `__('SAR')` supplies the currency symbol. Audit `InsightFormatter::money()` and all member-portal Blade for raw `Number::format` with `app()->getLocale()`.

CSS safety net on member panel:

```css
.fi-panel-member .ff-member-amount,
.fi-panel-member .ff-member-numeric {
    font-variant-numeric: lining-nums tabular-nums;
}
```

### 13.3 Currency & monetary symbols

Saudi Arabia’s **official riyal sign** (SAMA, approved February 2025; Unicode **U+20C1** *SAUDI RIYAL SIGN*) replaces the legacy Arabic abbreviation `ر.س` in all **Arabic-locale** member-facing UI and documents.

| Locale | Symbol | Placement | Example |
|--------|--------|-----------|---------|
| **English** | `SAR` (letters) | **Before** amount | `SAR 3,240.00` |
| **Arabic** | `⃁` (U+20C1) | **Before** amount (SAMA convention) | `⃁ 3,240.00` |

**Implementation (single source of truth):**

- Keep using `__('SAR')` everywhere amounts are formatted — do not hard-code symbols in Blade.
- Update `lang/ar.json`: `"SAR": "\u20C1"` (not `ر.س`, not U+FDFC `﷼` which is the **Iranian** rial sign).
- `MoneyDisplay::format()` and `x-member.amount`: `{__('SAR')} {western_digits}` for both locales; spacing: one space between symbol and amount.
- Wrap the symbol in `<span class="ff-sar-symbol" dir="ltr">` when embedded in RTL paragraphs so the sign stays with the number.

**Font support (required for implementation):**

The glyph is new (Unicode 17.0); system fonts may not render it yet. Plan for:

1. **Web / member portal** — load a font that includes U+20C1 (e.g. SAMA-published *Saudi Riyal* font or updated Noto stack) via `theme.css` + `partials/arabic-fonts.blade.php`.
2. **PDF statements** — register the same font in DomPDF for Arabic PDFs.
3. **Fallback** — if a future runtime check shows missing glyph, fall back to `SAR` text (not `ر.س`) and log once; prefer shipping the font over silent fallback.

```css
.ff-sar-symbol {
    font-family: 'Saudi Riyal', 'Noto Sans Symbols 2', var(--ff-font-arabic), sans-serif;
    font-variant-numeric: lining-nums tabular-nums;
}
```

**Additional rules:**

- **Credits** — green, optional `+` before symbol: `+SAR 2,000.00` / `+⃁ 2,000.00`
- **Debits** — red, minus before symbol: `−SAR 1,500.00` / `−⃁ 1,500.00`
- **Chip labels** — translate; amounts use symbol + Western digits
- **Long form** — `__('Managed in :currency (Saudi Riyal)', ['currency' => __('SAR')])` — in Arabic, `:currency` renders as `⃁`
- **SMS** — short form `⃁ 1500` (UTF-8); verify Twilio segment length for Arabic body
- **Do not use** U+FDFC `﷼` (Iranian rial) or postfix `ر.س` in new work (legacy `ar.json` value will be replaced)

### 13.4 RTL layout & typography

| Area | Requirement |
|------|-------------|
| **Direction** | `html[dir='rtl']` when `app()->getLocale() === 'ar'` (existing behavior) |
| **Spacing** | Tailwind logical properties: `ms-`, `me-`, `ps-`, `pe-`, `start`, `end` — no `ml-`/`mr-`/`pl-`/`pr-` in new components |
| **Icons** | Chevron / arrow icons flip in RTL (`rtl:rotate-180` where directional) |
| **Sidebar** | Profile block and nav mirror; collapsible state preserved |
| **Panels** | Head actions use chevron icon + translated label, not hard-coded ASCII arrow |
| **Tables** | First column aligns `start`; credit/debit columns stay scannable in RTL |
| **Arabic fonts** | `partials/arabic-fonts` + `arabic-typography.css` already loaded in `MemberPanelProvider` |
| **Member names** | `ff-arabic-name` class for Arabic names; `unicode-bidi: isolate` where mixed LTR/RTL |

### 13.5 Per-surface bilingual checklist (portal UI)

| Surface | English | Arabic | Digits | Symbols |
|---------|---------|--------|--------|---------|
| Dashboard notices | ✓ | ✓ | Western | `⃁` / `SAR` |
| Cash / fund balance cards | ✓ | ✓ | Western | Currency prefix |
| Loan progress (%, EMIs) | ✓ | ✓ | Western | `%` |
| Quick actions | ✓ | ✓ | Western in subtitles | Currency in subtitles |
| Activity table | ✓ | ✓ | Western | CR/DR chips translated |
| Deposit / cash-out forms | ✓ | ✓ | Western in inputs | Currency hint |
| Loan request wizard | ✓ | ✓ | Western | Tier amounts |
| Settings tabs | ✓ | ✓ | Western | — |
| Login / profile picker | ✓ | ✓ | Western | — |
| Notification preferences UI | ✓ | ✓ | Western | — |
| Help / FAQ (when added) | ✓ | ✓ | Western | — |

### 13.6 Testing (required before each phase merges)

**Portal UI**

- [ ] Member portal feature tests pass in **`en`**
- [ ] Same tests with locale **`ar`** where applicable
- [ ] Assert rendered HTML contains **no** Arabic-Indic digits (`\x{0660}-\x{0669}`) in amount nodes
- [ ] Assert `⃁` (U+20C1) when locale is `ar` and `SAR` when `en`
- [ ] Manual smoke: language switch on dashboard, cash page, loan view — RTL layout intact

**Outputs (§14)**

- [ ] At least one notification class tested per category in `en` + `ar` (snapshot title/body)
- [ ] Statement PDF rendered for `ar` member: RTL `dir`, translated headings, Western amounts, `⃁` (U+20C1)
- [ ] Queued notification job restores member `preferred_locale` before `toArray()` / `toMail()`
- [ ] Optional architecture test: new member Blade must not hard-code `SAR` without `__()`

### 13.7 Strings to add early (representative)

Add Arabic equivalents in `lang/ar.json` for all new portal copy, including:

- Navigation group labels (`Overview`, `My accounts`, `History`, `Self-service`)
- Notice templates (EMI due, contribution due, delinquent)
- Quick action titles/subtitles
- Chip statuses (`Collected`, `Pending`, `Exempt`, `Cleared`, `Processed`)
- Empty states (`No recent activity`, `No active loans`)
- Settlement wizard (`Roll-up (compress)`, `Skip cycles`)
- Notification preference category labels/descriptions (currently English literals in `NotificationPreferenceService::CATEGORIES`)

Keep keys identical to English source strings per project convention.

---

## 14. Member-facing outputs — full bilingual inventory

Every channel below must render in the member's **`preferred_locale`** (`en` or `ar`), with **Western digits** for all numbers and **`__('SAR')`** for currency display.

### 14.1 Output channels overview



![Diagram 2](../_assets//var/www/fundflow-saas/docs/member-portal-redesign-plan/diagram-02.png)



### 14.2 Locale resolution (critical for async)

| Trigger | Locale source | Action |
|---------|---------------|--------|
| **Live HTTP request** (portal, PDF download) | `app()->getLocale()` from session / language switch | Already works if session locale matches user |
| **Queued notifications** | `$notifiable->preferredLocale()` | Wrap `toArray` / `toMail` / `toSms` in locale setter |
| **Scheduled commands** (`contributions:notify`, `loans:send-due-notifications`, `statements:generate`) | Per-member `preferred_locale` | Loop members; `app()->setLocale()` per iteration |
| **Admin-initiated send** (broadcast, message) | Recipient's `preferred_locale` | Not the admin's UI locale |
| **PDF on download** | Requesting user's locale | `StatementPdfController` — set locale before `loadView` |

**Implementation pattern (add shared trait or helper):**

```php
// App\Support\MemberLocale::using($user, fn () => $notification->toArray($user));
```

Apply in `DeliversToMemberChannels`, queued jobs, and `MonthlyStatementService::sendNotification()`.

### 14.3 In-app notifications (Filament database)

**Path:** `app/Notifications/Tenant/*` (member-facing subset below)

All use `__()` for title/body **at send time** in the member's locale. Action button labels must also be translated (`View my contributions`, `Download statement`, etc.).

| Notification | Category | Channels |
|--------------|----------|----------|
| `ContributionDueNotification` | Contributions | DB, mail, SMS, WA |
| `ContributionPostedNotification` | Contributions | DB, mail, SMS, WA |
| `LoanRepaymentDueNotification` | Loan repayment | DB, mail, SMS, WA |
| `LoanRepaymentAppliedNotification` | Loan repayment | DB, mail, SMS, WA |
| `LoanSubmittedNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanApprovedNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanRejectedNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanDisbursedNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanPartialDisbursementNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanSettledNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanEarlySettledNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanDefaultWarningNotification` | Loan alerts | DB, mail, SMS, WA |
| `LoanDefaultGuarantorNotification` | Loan alerts | DB, mail, SMS, WA |
| `GuarantorLoanApplicationNotification` | Loan alerts | DB, mail, SMS, WA |
| `LoanEligibilityOverrideApprovedNotification` | Loan activity | DB, mail, SMS, WA |
| `LoanEligibilityOverrideRejectedNotification` | Loan activity | DB, mail, SMS, WA |
| `FundPostingAcceptedNotification` | Account alerts | DB, mail, SMS, WA |
| `FundPostingRejectedNotification` | Account alerts | DB, mail, SMS, WA |
| `CashOutRequestAcceptedNotification` | Account alerts | DB, mail, SMS, WA |
| `CashOutRequestRejectedNotification` | Account alerts | DB, mail, SMS, WA |
| `MonthlyStatementNotification` | Statements | DB, mail |
| `DependentAllocationChangedNotification` | Allocations | DB, mail, SMS, WA |
| `DelinquencyDigestNotification` | Loan alerts | DB (admin-facing digest — verify scope) |

**Admin broadcast** (`MemberPortalNotificationService`): title/body are **free text** from admin — not auto-translated. Options:

- Document that admins should write in the member's language or both; **or**
- Phase 2: optional bilingual title/body fields on send form.

**Notification preferences** (`MyNotificationPreferencesPage`): translate `NotificationPreferenceService::CATEGORIES` labels/descriptions via `__()` when rendering; channel names (`Email`, `SMS`, `In-app`) translated.

**Notification bell UI:** Filament renders stored title/body as-is (already localized at send). Empty states, "Mark all as read", timestamps: translate chrome.

### 14.4 Email

**Trait:** `DeliversToMemberChannels::toMail()` — subject + body from `toArray()` payload.

| Requirement | Detail |
|-------------|--------|
| **Subject / body** | Built under member locale; amounts via shared money formatter |
| **Laravel `MailMessage`** | Consider custom markdown template with RTL `dir` for `ar` |
| **Statement email** | `MonthlyStatementNotification` — action button `__('Download statement')` |
| **Footer / branding** | Fund name may be Arabic; numeric content Western |

### 14.5 SMS & WhatsApp

**Trait:** `DeliversToMemberChannels::toSms()` / `toWhatsApp()` — concatenated title + body.

| Requirement | Detail |
|-------------|--------|
| **Length** | Arabic messages may be longer; keep amounts short (`⃁ 1500`) |
| **Encoding** | UTF-8 for Arabic text |
| **Digits** | Western only in amounts, dates, member numbers |
| **Preference respect** | `NotificationPreferenceService` + `MemberCommunicationPreference` per category |

### 14.6 PDF — monthly statements

**Files:** `resources/views/pdf/monthly-statement.blade.php`, `StatementPdfController`, `MonthlyStatementService`

| Item | Current state | Required |
|------|---------------|----------|
| **HTML `dir`** | `rtl` when `ar` | Keep |
| **Section headings** | `__()` wrapped | Keep; audit all strings |
| **Currency in PDF** | Raw `$currency` code (`SAR`) | Use `__('SAR')` / formatted money helper |
| **Amounts** | `number_format()` | Western digits; thousand separators OK |
| **Transaction descriptions** | Stored English ledger text from DB | **Translate at render** using reference type map (see below) |
| **Transaction types** | Raw `credit`/`debit` | Translate column header; type chip translated |
| **Period label** | `period_formatted` on model | Regenerate with member locale on download, not only at batch generation |
| **Arabic font in PDF** | DejaVu Sans (limited Arabic) | Evaluate DomPDF Arabic font (e.g. Noto Sans Arabic) for statement PDFs |
| **Footer disclaimer** | `StatementSettings::footerDisclaimer()` | Tenant may supply AR+EN text; document setting |

**Ledger description strategy:** Prefer translating at PDF/UI render time from structured fields (`reference_type`, `reference_id`, period) rather than re-translating free-text descriptions. Add `Transaction::memberFacingDescription(?string $locale)` used by activity feed + PDF.

### 14.7 Direct messages

**Paths:** `DirectMessagingService`, `MyMessageResource`, `view-my-message.blade.php`

| Element | Bilingual approach |
|---------|-------------------|
| **UI chrome** | Thread list, reply form, attachments, empty states — `__()` |
| **Message body** | **Author's language** (admin or member); do not machine-translate |
| **Timestamps** | Localized format; Western digits in date parts |
| **Admin compose (tenant panel)** | UI translated; message content free text |
| **Unread badge / notifications** | `__('New message from :fund', …)` for system-generated alerts |

### 14.8 Support requests

**Path:** `SupportPage`, `SupportRequest` model

| Element | Approach |
|---------|----------|
| **Category options** | `SupportRequest::categoryOptions()` → `Lang::transOptions()` or `__()` per category |
| **Form labels / validation** | Translated |
| **Member message body** | User-authored — no auto translation |
| **Confirmation notification** | Translated system message to member |
| **Admin-facing** | Tenant panel in admin locale (out of member portal scope) |

### 14.9 Household & member requests

| Flow | Strings to translate |
|------|---------------------|
| **Dependents list / impersonation** | Actions, confirmations, status |
| **Member requests** (independence, add/remove dependent) | `MyMemberRequestsTableWidget`, modal headings, success toasts |
| **Contribution allocation changes** | `DependentAllocationChangedNotification` + settings UI |
| **Parent portal return** | `ReturnToParentPortalAction` label |

### 14.10 Forms, validation & business errors

Applies to all member portal forms (deposit, cash-out, loan apply, contribution settings, profile edit):

| Type | Rule |
|------|------|
| **Filament validation** | Custom rules use `__()` messages |
| **Service exceptions** shown to member | `InvalidArgumentException` messages via `__()` before throw or catch wrapper |
| **Success toasts** | `Notification::make()->title(__('…'))` |
| **Eligibility reasons** | `LoanService::checkEligibility()` reasons array — each reason translatable key |
| **Blocked portal login** | `MemberLoginPage` — status messages for suspended/delinquent/terminated |

### 14.11 Statements list & downloads (portal UI)

**Path:** `MyStatementResource`, `member-monthly-statement-insights.blade.php`

- Column headers, period labels, download action, empty state — translated
- Download link hits PDF controller with member session locale
- Insights widget: Western digits, `__('SAR')`

### 14.12 Activity feed & exports (redesign)

| Output | Bilingual |
|--------|-----------|
| **Unified activity page** | Filter chip labels, column headers, type badges |
| **Future CSV/Excel export** | UTF-8 with BOM for Excel Arabic; headers translated at export time; Western digits |
| **Loan schedule export** | Same rules as PDF |

### 14.13 Login, enrollment & public shell (adjacent)

Member journey often starts outside the panel:

| Surface | Note |
|---------|------|
| **`MemberLoginPage`** | Household profile picker, errors — bilingual |
| **Membership enrollment wizard** | Public tenant routes — same numeric/symbol rules |
| **PWA meta / manifest** | App name may be tenant-configured Arabic |
| **Status footer banners** | `MemberPanelProvider` render hooks — translate |

### 14.14 What stays untranslated

| Content | Reason |
|---------|--------|
| Internal enum values in DB | `posted`, `pending`, `active` |
| API / machine reference types | Mapped to labels at display |
| Admin-only tenant panel | Separate locale (admin's preference) |
| Log lines | English for operators |
| Attachment filenames | User-supplied |

### 14.15 Implementation checklist by redesign phase

| Phase | Bilingual deliverables |
|-------|------------------------|
| **0** | Sign-off: output inventory (this section); PDF font decision |
| **1** | `MoneyDisplay` + `x-member.amount`; `MemberLocale` helper; translate notification preference categories |
| **2** | Dashboard notices + all new strings in `ar.json`; notification snapshot tests |
| **3** | Nav labels; merged Help/Messages UI strings |
| **4** | Cash/fund pages, deposit/cash-out validation messages |
| **5** | Loan wizard, settlement, eligibility strings |
| **6** | Activity feed type map; strip duplicate widgets; `Transaction::memberFacingDescription()` |
| **7** | Settings tabs; FAQ strings; household copy |
| **Cross-cutting** | Audit all `Notifications/Tenant/*` for locale wrapper in queue; statement PDF currency + font; email RTL template |

---

## 15. Shared formatting helpers (implementation)

Introduce or extend centralized helpers used by **UI, PDF, email, and SMS**:

| Helper | Responsibility |
|--------|----------------|
| `MoneyDisplay::format()` | Western digits + `__('SAR')` symbol |
| `MemberLocale::using(User $user, callable $fn)` | Temporarily set locale for async output |
| `Transaction::memberFacingDescription()` | Localized ledger line for activity + PDF |
| `MemberDateDisplay::format()` | `translatedFormat` for words, Western digits for numbers |
| `FundPostingNotificationFormatter` | Extend pattern to other domain formatters |

Ensure `InsightFormatter`, statement PDF, and notification bodies **all call the same money helper** — no one-off `number_format` + hardcoded `SAR`.

---

## 16. Value-add features (simple, high-impact)

These are **missing today** but add clear member value. Scope is intentionally small: no custom report builder, no separate analytics app, no announcement CMS. Everything routes through existing data and settings where possible.

### 16.1 Gap analysis — what exists vs what's missing

| Area | Have today | Missing (planned) |
|------|------------|-------------------|
| **Communications** | Direct messages, support tickets, notification bell (recent only) | Unified **Communications** page; **alert history** (past SMS/email/in-app); deposit **bank instructions** on portal |
| **Documents** | Monthly statement PDF (admin-generated periods) | **On-demand** activity export; **loan schedule PDF**; optional deposit receipt view |
| **Analytics** | Buried KPIs, sparkline in dense dashboard | **One expandable insights panel** (4 stats + 6-month trend); **pending actions** list |
| **Help** | Support form only | **FAQ accordion** (tenant text, en/ar) |
| **Settings** | Scattered pages | Merged tabs incl. read-only **payout bank details** (if on file) |

**Explicitly out of scope (keep simple):**

- Custom date-range statement PDF builder (beyond activity CSV + existing monthly PDFs)
- Member-facing notification log admin tools
- Machine translation of message bodies
- Real-time charts / third-party analytics
- Separate mobile app beyond existing PWA

### 16.2 Communications

#### A. Communications center (single Help nav destination)

Replace fragmented Messages + Support with one page, **three tabs**:

| Tab | Source | Purpose |
|-----|--------|---------|
| **Messages** | `MyMessageResource` (embedded list) | Two-way admin ↔ member threads |
| **Requests** | `SupportPage` + `MyMemberRequestsTableWidget` | Support tickets + household requests |
| **Alert history** | `NotificationLog` scoped to `auth()->id()` | Read-only list: date, channel, subject, status |

**Alert history rules:**

- Show `database`, `mail`, `sms`, `whatsapp` rows from `notification_logs` for the logged-in user
- Paginated table; no resend; Western digits in timestamps
- Bilingual column headers; subject/body already localized at send time (§14)
- Empty state: `__('No alerts yet')`

**Dashboard link:** unread messages badge stays on sidebar; "View all communications →" in notice zone when unread > 0.

#### B. Fund deposit instructions (Cash page)

Enrollment already shows **Bank transfer details** from tenant settings; the member deposit flow does **not**.

Add a compact **info panel** on Cash account (above deposit form):

- Fund name, bank name, IBAN, reference hint (`Member #…`)
- Reuse settings keys from enrollment (`Settings` → membership fee bank fields or dedicated fund deposit settings)
- Fallback string already in `lang/ar.json`: *"Bank transfer details have not been configured…"*
- Copy-to-clipboard on IBAN (optional, simple)

#### C. New message notification (small polish)

When admin sends a direct message, ensure member gets in-app notification with `__('New message')` + link to Communications → Messages tab (may already partial — verify in implementation).

### 16.3 Documents

Enhance **Statements** page (do not add a new nav item). Two sections:

#### A. Monthly statements (existing)

- List + PDF download per period (unchanged)
- Bilingual list UI; PDF per §14.6

#### B. Download center (new panel on same page)

Simple form — **no** multi-step wizard:

| Export | Format | Scope |
|--------|--------|-------|
| **Activity** | CSV | Date from / to; all account types; uses unified activity query (§6.5) |
| **Loan schedule** | PDF | Active loan only; installments table + summary |
| **Contributions** | CSV | Optional phase 8b — all posted contributions |

**Rules:**

- UTF-8 BOM on CSV for Excel + Arabic headers
- Western digits throughout
- Filename pattern: `activity-{member_number}-{from}-{to}.csv`
- Loan schedule PDF: reuse statement PDF stack + Arabic font decision from §14.6

**Not in v1:** combined cash+fund custom PDF builder (prototype "Generate statement" with many types) — defer until usage proves need.

#### C. Deposit receipt (optional, phase 8b)

When `FundPosting` status → `accepted`, show **View receipt** on deposit history row:

- Minimal PDF or printable HTML: amount, date, reference, fund name
- Bilingual template; no new data model

### 16.4 Analytics (simple — dashboard only)

**No separate Analytics nav item.** One collapsible panel on Overview: **"My insights"** (default collapsed on mobile).

| Widget | Data source | Display |
|--------|-------------|---------|
| **6-month contributions** | `EnrichesMemberPortalDashboard::contributionSparkline()` | Small bar/sparkline (reuse) |
| **YTD contributed** | Sum posted contributions current calendar year | Stat chip |
| **YTD repaid** | Sum loan repayments current year | Stat chip |
| **Borrow headroom** | `LoanSettings::maxLoanAmountForMember(fund)` − active exposure | Stat chip |
| **Cycles missed (12 mo)** | Contribution query `is_late` or missed status | Stat chip (red if > 0) |

**Guarantor exposure** (conditional): if guarantor on active loans, show one line: `__('Guarantor on :n active loans')` + link — reuse `MemberGuaranteedLoanInsightsService` counts; no chart.

**Pending actions** (not analytics — operational): small list in notice zone or above quick actions:

- Pending deposit review
- Pending cash-out
- Pending loan application
- Unread messages count

Reuse existing models; no new analytics service — extend `MemberPortalInsightsService::snapshot()` with `insights_expandable` and `pending_actions` keys.

### 16.5 Help & FAQ

Static **FAQ accordion** on Help page (below tabs or fourth tab):

- Tenant-configurable via **Settings** → two textarea fields: `member_faq_en`, `member_faq_ar` (markdown or plain Q&A JSON)
- **Phase 8 minimum:** ship with hard-coded FAQ entries from prototype (`member-portal-prototype.html` lines 686–695) in `lang/en` + `lang/ar.json` as structured keys — tenant override later
- Bilingual; Western digits in answers where numeric

### 16.6 Settings additions

| Tab | Addition |
|-----|----------|
| **Profile** | Existing |
| **Payout details** | Read-only member bank fields used for cash-out (if stored); "Contact support to update" |
| **Notifications** | Existing preferences page merged in |
| **Contributions** | Existing contribution settings merged in |

### 16.7 Feature × phase map

| Feature | Phase |
|---------|-------|
| Design system + bilingual helpers | 1 |
| Dashboard zones + pending actions | 2 |
| Communications center shell | 3 |
| Cash page + bank instructions | 4 |
| Loan schedule PDF | 5 |
| Activity CSV + insights expandable | 6 |
| FAQ + Settings merge + alert history | 7–8 |

### 16.8 Tests (value-add)

- [ ] Member sees only own `NotificationLog` rows
- [ ] Activity CSV export respects date range and locale headers
- [ ] Loan schedule PDF renders in `ar` with Western digits
- [ ] Bank instructions panel shows configured IBAN or fallback message
- [ ] Insights expandable hidden when member has no contribution history

### 16.9 Bilingual note

All §16 surfaces follow §13–15: translated UI, `⃁`/`SAR`, Western digits, RTL layout, `MemberLocale` for any async document generation.

---
