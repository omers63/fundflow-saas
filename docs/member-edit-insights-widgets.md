# Member view/edit workspace — overview dashboard

Compact overview dashboard on **View member** and **Edit profile** (`/{record}` and `/{record}/edit`), above relation tabs / the profile form.

Members use a **view/edit split**: `ViewMember` is the operator workspace; `EditMember` is the profile form with the same overview strip for context while editing.

## Layout overview

The dashboard renders in a single Livewire request (cached 30s).

1. **Header** — Overview title, member number, status badge
2. **KPI cards** — Cash, Fund, Monthly, Lifetime posted contributions  
3. **Portfolio totals** — Total loans, Total loans value, Loan Repayments Total, Collection Total (posted contributions + repayments)  
4. **Open-cycle chip** — Posted / Ready / Need cash / Exempt / Loan EMI  
5. **Arrears chip** — When cheap signals detect overdue installments or prior-period contribution gaps (no full delinquency evaluator on load)  
6. **Active loan card** — Outstanding, installment progress bar, link to Loans tab  
7. **Quick links** — Ledger, Contributions, Loans  
8. **Household strip** — Parent link and dependents when applicable 

## Header actions (view page)

- **Contribute** / **Allocate** / **Edit profile** — Primary row
- **Treasury**, **Communicate**, **Membership**, **Compliance** — Same groups as before; heavy visibility checks deferred to modal open where noted in code

Page heading: member name. Subheading: `MEM-#### · Status` (plain DB status, no arrears suffix on load).

## Tabs

1. **Profile** — Infolist overview
2. **Ledger** — Member transactions
3. **Cycle history** — Contributions
4. **Loans**
5. **Household** — When dependents exist
6. **Guarantor** — When exposure exists
7. **Repayments** — When legacy paid installment rows exist
8. **Messages** — When member has a linked portal user

The **Accounts** tab was removed; cash/fund cards link to account views instead.

## Key files

| Purpose | Path |
|--------|------|
| Summary data | `app/Services/MemberWorkspaceSummaryService.php` |
| Blade UI | `resources/views/filament/tenant/pages/member-workspace-summary.blade.php` |
| View page wiring | `app/Filament/Tenant/Resources/Members/Pages/ViewMember.php` |
| Edit page wiring | `app/Filament/Tenant/Resources/Members/Pages/EditMember.php` |
| Tab badge suppression | `app/Filament/Tenant/Resources/Members/Concerns/SuppressesMemberWorkspaceTabBadges.php` |
| Delinquency header actions | `app/Filament/Support/MemberDelinquencyActions.php` |
| Contribution header actions | `app/Filament/Tenant/Resources/Members/Concerns/InteractsWithMemberContributionHeaderActions.php` |
| List-page insights (unchanged) | `app/Services/MemberInsightsService.php`, `MemberInsightsWidget` |

## Refresh behavior

- Summary cached **30s** via `TenantRuntimeCache`
- Bust cache on `refresh-member-detail-insights` Livewire event (treasury mutations, contribute, allocate, etc.)
- Edit save also forgets the workspace summary cache before redirecting to view
- `MemberResource::dispatchMemberDetailInsightsRefresh()` dispatches that event on the current Livewire component

## Tests

- `tests/Unit/MemberWorkspaceSummaryServiceTest.php` — Summary shape, lifetime contributions, loan chip, arrears signal, cache bust
- `tests/Feature/Tenant/ViewMemberPerformanceTest.php` — Query budget on initial shell load
- `tests/Feature/Tenant/EditMemberPageTest.php` — Workspace UI, edit overview strip, tabs, header actions

## Related docs

- Delinquency actions: `docs/loan-delinquency-workflow.md`
- Account detail pattern: `resources/views/filament/tenant/widgets/account-detail-insights.blade.php`
- Ops list overviews (shared chrome): Members list + treasury/roster queues use `resources/views/filament/tenant/partials/ops-overview/` and restyled `insights-{head,hero,kpi-strip}`; Bank/SMS/Reconciliation/Delinquency shells share the same density. Thin Fund-out / Cash-transfer widgets: `FundOutRequestInsightsService`, `MemberCashTransferRequestInsightsService`.
