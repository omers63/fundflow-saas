@php
/** @var \App\Models\Tenant\Member|null $member */
@endphp

<div class="fi-wi-widget ff-freeze-status-stack">
    @if ($isFrozen && $member)
        <div class="ff-freeze-callout ff-freeze-callout--warning">
            <p class="ff-freeze-callout__title">{{ __('Membership frozen — read-only portal') }}</p>
            <p class="ff-freeze-callout__body">
                @if ($withinPlan && $indefinitePlan)
                    {{ __('Indefinite freeze. Contributions and cash-out are paused. EMIs are deferred each cycle; late fees stay suppressed until you or an admin unfreeze.') }}
                @elseif ($withinPlan)
                        {{ __('Planned freeze: :remaining of :requested cycle(s) remaining. Contributions and cash-out are paused. EMIs are deferred; late fees are suppressed until the plan ends.', [
            'remaining' => (int) $member->freeze_cycles_remaining,
            'requested' => (int) $member->freeze_cycles_requested,
        ]) }}
                @elseif ($planExhausted)
                    {{ __('Your freeze plan has ended, but membership stays frozen until you or an admin unfreezes. Late fees and delinquency may apply again. Use Requests → Unfreeze when ready.') }}
                @else
                    {{ __('Your membership is frozen. Use Requests to unfreeze or extend the plan.') }}
                @endif
            </p>
            <dl class="ff-freeze-callout__meta">
                <div>
                    <dt>{{ __('Frozen since') }}</dt>
                    <dd>{{ $member->frozen_at?->toDateString() ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('EMI cycles deferred') }}</dt>
                    <dd>{{ (int) ($member->freeze_emi_cycles_pushed ?? 0) }}</dd>
                </div>
            </dl>
        </div>
    @endif

    @if ($pendingAccept->isNotEmpty())
        <div class="ff-freeze-callout ff-freeze-callout--info">
            <p class="ff-freeze-callout__title">{{ __('Guarantor acceptance required') }}</p>
            <p class="ff-freeze-callout__body">
                {{ __('Review each proposal, then accept or decline. Acceptance updates the loan guarantor immediately.') }}
            </p>
            <ul class="ff-freeze-accept-list">
                @foreach ($pendingAccept as $req)
                        <li class="ff-freeze-accept-item">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Loan #:id', ['id' => $req->loan_id]) }}
                                </div>
                                <div class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('Borrower: :name · Replacing: :outgoing', [
            'name' => $req->loan?->member?->name ?? '—',
            'outgoing' => $req->outgoingGuarantor?->name ?? '—',
        ]) }}
                                </div>
                            </div>
                            <div class="ff-freeze-accept-item__actions">
                                <x-filament::button size="sm" color="primary"
                                    wire:click="acceptGuarantorReplacement({{ $req->id }})">
                                    {{ __('Accept') }}
                                </x-filament::button>
                                <x-filament::button size="sm" color="gray" wire:click="rejectGuarantorReplacement({{ $req->id }})">
                                    {{ __('Decline') }}
                                </x-filament::button>
                            </div>
                        </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (!empty($needsReplacement))
        <div class="ff-freeze-callout ff-freeze-callout--danger">
            <p class="ff-freeze-callout__title">{{ __('Borrowers must replace you as guarantor') }}</p>
            <p class="ff-freeze-callout__body">
                {{ __('Freeze cannot proceed until each loan below has a new guarantor who has accepted.') }}
            </p>
            <ul class="ff-freeze-callout__list">
                @foreach ($needsReplacement as $loan)
                    <li>{{ __('Loan #:id — borrower :name', ['id' => $loan->id, 'name' => $loan->member?->name ?? '—']) }}</li>
                @endforeach
            </ul>
            <div class="mt-3">
                <x-filament::button
                    size="sm"
                    color="warning"
                    icon="heroicon-o-bell-alert"
                    wire:click="notifyBorrowersToReplaceGuarantor"
                >
                    {{ __('Notify borrowers to replace guarantor') }}
                </x-filament::button>
            </div>
        </div>
    @endif
</div>