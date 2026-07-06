# Member view workspace — inline summary

Compact workspace on the **View member** page (`/{record}`), styled with a lean summary strip above relation-manager tabs.

Members use a **view/edit split**: `ViewMember` is the operator workspace; `EditMember` is profile form only.

## Layout overview

The inline summary renders **above** the Profile tab and relation-manager tabs in a single Livewire request.

1. **Cash & fund balance cards** — Link to each account view  
2. **Open-cycle chip** — Posted / Ready / Need cash / Exempt / Loan EMI  
3. **Arrears chip** — When cheap signals detect overdue installments or prior-period contribution gaps (no full delinquency evaluator on load)  
4. **Active loan one-liner** — When an active loan exists, with link to Loans tab  
5. **Household strip** — Parent link and dependents when applicable  

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
| Tab badge suppression | `app/Filament/Tenant/Resources/Members/Concerns/SuppressesMemberWorkspaceTabBadges.php` |
| Delinquency header actions | `app/Filament/Support/MemberDelinquencyActions.php` |
| Contribution header actions | `app/Filament/Tenant/Resources/Members/Concerns/InteractsWithMemberContributionHeaderActions.php` |
| List-page insights (unchanged) | `app/Services/MemberInsightsService.php`, `MemberInsightsWidget` |

## Refresh behavior

- Summary cached **30s** via `TenantRuntimeCache`  
- Bust cache on `refresh-member-detail-insights` Livewire event (treasury mutations, contribute, allocate, etc.)  
- `MemberResource::dispatchMemberDetailInsightsRefresh()` dispatches that event on the current Livewire component  

## Tests

- `tests/Unit/MemberWorkspaceSummaryServiceTest.php` — Summary shape, loan chip, arrears signal, cache bust  
- `tests/Feature/Tenant/ViewMemberPerformanceTest.php` — Query budget on initial shell load  
- `tests/Feature/Tenant/EditMemberPageTest.php` — Workspace UI, tabs, header actions  

## Related docs

- Delinquency actions: `docs/loan-delinquency-workflow.md`  
- Account detail pattern: `resources/views/filament/tenant/widgets/account-detail-insights.blade.php`  
