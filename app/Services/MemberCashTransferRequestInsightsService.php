<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\TenantRuntimeCache;

final class MemberCashTransferRequestInsightsService
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
            'member_cash_transfer_request_insights.v1',
            self::CACHE_SECONDS,
            function (): array {
                $pending = MemberCashTransferRequest::query()->pending()->count();
                $accepted = MemberCashTransferRequest::query()->where('status', 'accepted')->count();
                $rejected = MemberCashTransferRequest::query()->where('status', 'rejected')->count();

                $pendingAmount = (float) MemberCashTransferRequest::query()->pending()->sum('amount');
                $acceptedAmount = (float) MemberCashTransferRequest::query()->where('status', 'accepted')->sum('amount');

                $currency = (string) Setting::get('general', 'currency', 'USD');
                $pendingUrl = MemberCashTransferRequestResource::listUrl(['status' => ['value' => 'pending']]);
                $acceptedUrl = MemberCashTransferRequestResource::listUrl(['status' => ['value' => 'accepted']]);
                $rejectedUrl = MemberCashTransferRequestResource::listUrl(['status' => ['value' => 'rejected']]);
                $indexUrl = MemberCashTransferRequestResource::listUrl();

                $hero = $pending > 0
                    ? [
                        'title' => __('Cash transfers need attention'),
                        'subtitle' => trans_choice(':count pending', $pending, ['count' => $pending]),
                        'tone' => 'amber',
                    ]
                    : [
                        'title' => __('Queue clear'),
                        'subtitle' => __('No cash transfers awaiting review'),
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
