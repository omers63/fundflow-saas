<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\FundOutRequests\FundOutRequestResource;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\TenantRuntimeCache;

final class FundOutRequestInsightsService
{
    private const int CACHE_SECONDS = 30;

    /**
     * @return array{
     *     pending: int,
     *     accepted: int,
     *     rejected: int,
     *     total: int,
     *     pending_amount: float,
     *     accepted_amount: float,
     *     currency: string,
     *     pending_url: string,
     *     accepted_url: string,
     *     rejected_url: string,
     *     index_url: string,
     *     hero: array{title: string, subtitle: string, tone: string}
     * }
     */
    public function snapshot(): array
    {
        return TenantRuntimeCache::remember(
            'fund_out_request_insights.v1',
            self::CACHE_SECONDS,
            function (): array {
                $pending = FundOutRequest::query()->pending()->count();
                $accepted = FundOutRequest::query()->accepted()->count();
                $rejected = FundOutRequest::query()->rejected()->count();

                $pendingAmount = (float) FundOutRequest::query()->pending()->sum('amount');
                $acceptedAmount = (float) FundOutRequest::query()->accepted()->sum('amount');

                $currency = (string) Setting::get('general', 'currency', 'USD');
                $pendingUrl = FundOutRequestResource::listUrl(['status' => ['value' => 'pending']]);
                $acceptedUrl = FundOutRequestResource::listUrl(['status' => ['value' => 'accepted']]);
                $rejectedUrl = FundOutRequestResource::listUrl(['status' => ['value' => 'rejected']]);
                $indexUrl = FundOutRequestResource::listUrl();

                $hero = $pending > 0
                    ? [
                        'title' => __('Fund-outs need attention'),
                        'subtitle' => trans_choice(':count pending', $pending, ['count' => $pending]),
                        'tone' => 'amber',
                    ]
                    : [
                        'title' => __('Queue clear'),
                        'subtitle' => __('No fund-out requests awaiting review'),
                        'tone' => 'success',
                    ];

                return [
                    'pending' => $pending,
                    'accepted' => $accepted,
                    'rejected' => $rejected,
                    'total' => $pending + $accepted + $rejected,
                    'pending_amount' => $pendingAmount,
                    'accepted_amount' => $acceptedAmount,
                    'currency' => $currency,
                    'pending_url' => $pendingUrl,
                    'accepted_url' => $acceptedUrl,
                    'rejected_url' => $rejectedUrl,
                    'index_url' => $indexUrl,
                    'hero' => $hero,
                    'as_of' => BusinessDay::now()->toIso8601String(),
                ];
            }
        );
    }
}
