<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Models\Tenant\Account;
use App\Services\MemberPortalAccountDetailInsightsService;
use Filament\Widgets\Widget;

class MemberMyAccountDetailInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-my-account-detail-insights';

    protected int|string|array $columnSpan = 'full';

    public ?int $accountId = null;

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->accountId === null) {
            return [];
        }

        $account = Account::query()->find($this->accountId);

        if ($account === null) {
            return [];
        }

        return app(MemberPortalAccountDetailInsightsService::class)->snapshot($account);
    }
}
