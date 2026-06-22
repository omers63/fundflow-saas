<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use App\Models\Tenant\SmsTransaction;

final class SmsClearingInsightsService
{
    public function __construct(
        protected SmsClearingQueueService $queue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $counts = $this->queue->counts();
        $postedToday = SmsTransaction::query()
            ->whereNotNull('posted_at')
            ->whereDate('posted_at', today())
            ->count();

        return [
            'clearing_kpis' => [
                [
                    'label' => __('Open queue'),
                    'value' => (string) $counts['all'],
                    'sub' => __('Unposted SMS rows'),
                    'accent' => $counts['all'] > 0 ? 'amber' : 'emerald',
                    'url' => SmsClearingTabRegistry::listUrl(SmsClearingTabRegistry::TAB_QUEUE),
                ],
                [
                    'label' => __('Unmatched'),
                    'value' => (string) $counts['unmatched'],
                    'sub' => __('Need member assignment'),
                    'accent' => $counts['unmatched'] > 0 ? 'rose' : 'gray',
                    'url' => SmsClearingTabRegistry::listUrl(
                        SmsClearingTabRegistry::TAB_QUEUE,
                        queueFilter: SmsClearingTabRegistry::FILTER_UNMATCHED,
                    ),
                ],
                [
                    'label' => __('Ready to post'),
                    'value' => (string) $counts['ready_to_post'],
                    'sub' => __('Member matched'),
                    'accent' => $counts['ready_to_post'] > 0 ? 'sky' : 'gray',
                    'url' => SmsClearingTabRegistry::listUrl(
                        SmsClearingTabRegistry::TAB_QUEUE,
                        queueFilter: SmsClearingTabRegistry::FILTER_READY,
                    ),
                ],
                [
                    'label' => __('Posted today'),
                    'value' => (string) $postedToday,
                    'sub' => __('Cleared to member cash'),
                    'accent' => 'emerald',
                    'url' => SmsClearingTabRegistry::listUrl(SmsClearingTabRegistry::TAB_LEDGER),
                ],
            ],
        ];
    }
}
