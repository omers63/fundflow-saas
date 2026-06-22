@php
    use App\Filament\Support\MoneyDisplay;

    $member = \App\Support\Tenant\CurrentMember::get();
    $isDependent = $member !== null && $member->parent_member_id !== null;
    $current = $this->monthly_contribution_amount;
    $options = \App\Models\Tenant\Member::contributionAmountOptions();
    $minOpt = array_key_first($options);
    $maxOpt = array_key_last($options);

    $range = $maxOpt - $minOpt;
    $progress = $range > 0 ? round(($current - $minOpt) / $range * 100) : 0;

    $paidThisMonth = $member
        ? (int) \App\Models\Tenant\Contribution::query()
            ->posted()
            ->forPeriod((int) now()->month, (int) now()->year)
            ->where('member_id', $member->id)
            ->sum('amount')
        : 0;

    $totalEver = $member
        ? (int) \App\Models\Tenant\Contribution::query()
            ->posted()
            ->where('member_id', $member->id)
            ->sum('amount')
        : 0;
@endphp

@if ($this->allocationChangeBlocked)
    <x-filament::section class="mb-6" icon="heroicon-o-exclamation-triangle" icon-color="danger">
        <x-slot name="heading">{{ __('Allocation locked') }}</x-slot>
        <x-slot name="description">
            {{ $this->allocationChangeBlockedMessage }}
        </x-slot>
    </x-filament::section>
@endif

@if ($isDependent)
    <x-filament::section class="mb-6" icon="heroicon-o-information-circle" icon-color="warning">
        <x-slot name="heading">{{ __('Sponsored member') }}</x-slot>
        <x-slot name="description">
            {!! __('You are currently sponsored by a parent member. You can still update your own contribution allocation here. If you need to add/remove dependents or request independence, use <strong>My Dependents</strong> requests.') !!}
        </x-slot>
    </x-filament::section>
@endif

<div
    class="rounded-2xl bg-gradient-to-br from-primary-600 to-primary-700 dark:from-primary-700 dark:to-primary-900 p-6 text-white shadow-lg mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-primary-100 uppercase tracking-wide">{{ __('Monthly Contribution') }}
            </p>
            <p class="mt-1 text-4xl sm:text-5xl font-extrabold tracking-tight">
                {!! MoneyDisplay::html($current, null, precision: 0)?->toHtml() !!}
            </p>
            <p class="mt-2 text-sm text-primary-200">
                {{ __('Deducted automatically from your cash account each cycle') }}
            </p>
        </div>
        <div
            class="flex-shrink-0 hidden sm:flex h-20 w-20 items-center justify-center rounded-full bg-white/10 ring-2 ring-white/20">
            <x-heroicon-o-banknotes class="w-10 h-10 text-white" />
        </div>
    </div>

    <div class="mt-5">
        <div class="flex justify-between text-xs text-primary-200 mb-1.5">
            <span>{!! MoneyDisplay::html($minOpt, null, precision: 0)?->toHtml() !!}</span>
            <span
                class="font-medium text-white">{{ __('Your level: :progress% of max', ['progress' => $progress]) }}</span>
            <span>{!! MoneyDisplay::html($maxOpt, null, precision: 0)?->toHtml() !!}</span>
        </div>
        <div class="w-full rounded-full bg-white/20 h-2">
            <div class="h-2 rounded-full bg-white transition-all" style="width: {{ $progress }}%"></div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-6">
    <div
        class="relative overflow-hidden rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
        <div class="absolute inset-y-0 left-0 w-1 rounded-l-xl bg-teal-500"></div>
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 pl-2">
            {{ __('Paid This Month') }}
        </p>
        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white pl-2">
            @if ($paidThisMonth > 0)
                {!! MoneyDisplay::html($paidThisMonth, null, precision: 0)?->toHtml() !!}
                <span
                    class="ml-2 inline-flex items-center gap-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                    <x-heroicon-o-check-circle class="w-4 h-4" /> {{ __('Paid') }}
                </span>
            @else
                <span class="text-amber-600 dark:text-amber-400">{{ __('Pending') }}</span>
            @endif
        </p>
        <p class="mt-0.5 text-xs text-gray-400 pl-2">
            {{ now()->locale(app()->getLocale())->translatedFormat('F Y') }}
        </p>
    </div>

    <div
        class="relative overflow-hidden rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
        <div class="absolute inset-y-0 left-0 w-1 rounded-l-xl bg-primary-500"></div>
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 pl-2">
            {{ __('Total Contributed') }}
        </p>
        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white pl-2">
            {!! MoneyDisplay::html($totalEver, null, precision: 0)?->toHtml() !!}
        </p>
        <p class="mt-0.5 text-xs text-gray-400 pl-2">{{ __('Lifetime cumulative') }}</p>
    </div>
</div>

<x-filament::section>
    <x-slot name="heading">{{ __('Available Amounts') }}</x-slot>
    <x-slot name="description">
        {{ __('Multiples of SAR 500, from :min to :max.', [
    'min' => MoneyDisplay::format($minOpt, null, precision: 0),
    'max' => MoneyDisplay::format($maxOpt, null, precision: 0),
]) }}
        {!! __('Use <strong>Save allocation</strong> above to apply your new amount immediately; administrators are notified automatically.') !!}
    </x-slot>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
        @foreach ($options as $value => $label)
            @php
                $isActive = $value == $current;
                $amountTitle = MoneyDisplay::format($value, null, precision: 0);
            @endphp
            <div @class([
                'ff-member-contribution-option relative flex min-w-0 flex-col items-center justify-center rounded-xl p-3 text-center transition-all',
                'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-900/30' => $isActive,
                'ring-1 ring-gray-200 dark:ring-gray-700 bg-white dark:bg-gray-800 hover:ring-primary-300 dark:hover:ring-primary-700' => !$isActive,
            ])>
                @if ($isActive)
                    <div
                        class="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-primary-500">
                        <x-heroicon-o-check class="w-3 h-3 text-white" />
                    </div>
                @endif
                <p @class([
                    'ff-member-contribution-option__amount text-lg font-bold tabular-nums',
                    'text-primary-700 dark:text-primary-300' => $isActive,
                    'text-gray-700 dark:text-gray-300' => !$isActive,
                ]) title="{{ $amountTitle }}">
                    {!! MoneyDisplay::html($value, null, precision: 0)?->toHtml() !!}
                </p>
            </div>
        @endforeach
    </div>
</x-filament::section>