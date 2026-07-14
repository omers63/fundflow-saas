<div class="ff-member-loans-hub space-y-4">
    @include('filament.member.resources.my-loans.partials.active-loan-card', [
        'loan' => $loan,
        'currency' => $currency,
        'showSchedule' => $showSchedule,
        'canSettle' => ($loan['status'] ?? null) === 'active',
    ])
</div>
