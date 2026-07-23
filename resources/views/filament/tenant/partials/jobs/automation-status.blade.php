@php
use App\Models\Tenant\SystemJobRun;
use App\Support\AutomationAreaSummary;

$areas = AutomationAreaSummary::summarize(app(\App\Services\SystemJobRunnerService::class));
@endphp

<div class="grid gap-4 lg:grid-cols-2">
    @foreach ($areas as $area)
    @php($status = $area['last_status'])
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $area['label'] }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ trans_choice(':count job|:count jobs', $area['job_count'], ['count' => $area['job_count']]) }}
                </p>
            </div>
            @if ($status !== null)
                    <span @class([
                        'inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
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
                <span
                    class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ __('Never run') }}</span>
            @endif
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @if ($area['failures_last_7_days'] > 0)
                {{ trans_choice(':count failure in the last 7 days|:count failures in the last 7 days', $area['failures_last_7_days'], ['count' => $area['failures_last_7_days']]) }}
            @else
                {{ __('No failures in the last 7 days') }}
            @endif
        </p>

        <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10" role="list">
            @forelse ($area['jobs'] as $job)
                <li
                    class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $job['job_label'] }}</p>
                        <p class="mt-0.5 text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ $job['schedule'] }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end sm:gap-0.5">
                        @if ($job['last_status'] !== null)
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $job['last_status'] === SystemJobRun::STATUS_SUCCESS,
                                        'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => $job['last_status'] === SystemJobRun::STATUS_FAILED,
                                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200' => $job['last_status'] === SystemJobRun::STATUS_RUNNING,
                                        'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-200' => !in_array($job['last_status'], [SystemJobRun::STATUS_SUCCESS, SystemJobRun::STATUS_FAILED, SystemJobRun::STATUS_RUNNING], true),
                                    ])>
                                        {{ match ($job['last_status']) {
                                SystemJobRun::STATUS_SUCCESS => __('Success'),
                                SystemJobRun::STATUS_FAILED => __('Failed'),
                                SystemJobRun::STATUS_RUNNING => __('Running'),
                                default => __('Unknown'),
                            } }}
                                    </span>
                                    @if ($job['last_started_at'])
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">
                                            {{ \Illuminate\Support\Carbon::parse($job['last_started_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                        </span>
                                    @endif
                        @else
                            <span class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ __('Never') }}</span>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-2 text-xs text-gray-400">{{ __('No scheduled jobs in this area') }}</li>
            @endforelse
        </ul>
    </div>
    @endforeach
</div>