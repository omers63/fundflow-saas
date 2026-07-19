<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\SupportRequests\SupportRequestResource;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\SupportRequest;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;

final class AdminNotificationActions
{
    public static function review(string $label, string $url, string $name = 'review'): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(TenantAbsoluteUrl::resolve($url))
            ->markAsRead();
    }

    public static function reviewMember(Member $member, ?string $label = null): Action
    {
        return self::review(
            $label ?? __('Review member'),
            MemberResource::getUrl('view', ['record' => $member], panel: 'tenant'),
        );
    }

    public static function reviewMemberRequest(MemberRequest $request, ?string $label = null): Action
    {
        return self::review(
            $label ?? __('Review request'),
            MemberRequestResource::getUrl('view', ['record' => $request], panel: 'tenant'),
        );
    }

    public static function reviewSupportRequest(SupportRequest $request, ?string $label = null): Action
    {
        return self::review(
            $label ?? __('Review request'),
            SupportRequestResource::getUrl('index', panel: 'tenant'),
        );
    }

    public static function reviewCashOutRequest(CashOutRequest $request, ?string $label = null): Action
    {
        return self::review(
            $label ?? __('Review request'),
            self::cashOutRequestUrl($request),
        );
    }

    public static function cashOutRequestUrl(CashOutRequest $request): string
    {
        $request->loadMissing('member');

        if ($request->member instanceof Member) {
            return CashOutRequestResource::indexUrlForMember($request->member, 'pending');
        }

        return CashOutRequestResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);
    }
}
