@php
    use App\Models\Tenant\SystemJobRun;
    use App\Support\AutomationAreaSummary;

    $areas = AutomationAreaSummary::summarize(app(\App\Services\SystemJobRunnerService::class));
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($areas as $area)
    @php($status = $area['last_status'])
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $area['label'] }}</p>
            @if ($status !== null)
                    <span @class([
                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $status === SystemJobRun::STATUS_SUCCESS,
                        'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => $status === SystemJobRun::STATUS_FAILED,
                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200' => $status === SystemJobRun::STATUS_RUNNING,
                        'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-200' => !in_array($status, [SystemJobRun::STATUS_SUCCESS, SystemJobRun::STATUS_FAILED, SystemJobRun::STATUS_RUNNING], true),
                    ])>
                        {{ match ($status) {
                    SystemJobRun::STATUS_SUCCESS => __('OK'),
                    SystemJobRun::STATUS_FAILED => __('Failed'),
                    SystemJobRun::STATUS_RUNNING => __('Running'),
                    default => __('Unknown'),
                } }}
                    </span>
            @else
                <span class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ __('Never run') }}</span>
            @endif
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $area['schedule_hint'] }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            @if ($area['failures_last_7_days'] > 0)
                {{ trans_choice(':count failure in the last 7 days|:count failures in the last 7 days', $area['failures_last_7_days'], ['count' => $area['failures_last_7_days']]) }}
            @else
                {{ __('No failures in the last 7 days') }}
            @endif
        </p>
    </div>
    @endforeach
</div>