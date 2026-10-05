@php
    $show = count($goals) > 0 || $loanReadiness !== null;
@endphp

@if ($show)
    <div class="fi-wi-widget space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($loanReadiness)
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Loan readiness') }}</h3>
                <p class="mt-1 text-sm {{ $loanReadiness['eligible'] ? 'text-success-600' : 'text-gray-600 dark:text-gray-300' }}">
                    {{ $loanReadiness['summary'] }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Fund balance') }}: {{ number_format($loanReadiness['fund_balance'], 2) }}
                    · {{ __('Monthly contribution') }}: {{ number_format($loanReadiness['monthly_contribution'], 2) }}
                </p>
                <a href="{{ \App\Filament\Member\Pages\LoanCalculatorPage::getUrl() }}" class="mt-2 inline-block text-xs font-medium text-primary-600 hover:underline">
                    {{ __('Open loan calculator') }}
                </a>
            </div>
        @endif

        @if (count($goals) > 0)
            <div>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Savings goals') }}</h3>
                    <a href="{{ \App\Filament\Member\Pages\MemberSavingsGoalsPage::getUrl() }}" class="text-xs font-medium text-primary-600 hover:underline">
                        {{ __('Manage') }}
                    </a>
                </div>
                <ul class="space-y-3">
                    @foreach ($goals as $row)
                        @php($p = $row['progress'])
                        <li>
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['goal']->title }}</span>
                                <span class="text-gray-500">{{ number_format($p['progress_percent'], 0) }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-full rounded-full bg-primary-500" style="width: {{ min(100, $p['progress_percent']) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Fund :balance / :target', [
                                    'balance' => number_format($p['current_fund_balance'], 2),
                                    'target' => number_format($p['target_amount'], 2),
                                ]) }}
                                @if ($p['behind_schedule'])
                                    · <span class="text-danger-600">{{ __('Behind schedule') }}</span>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
