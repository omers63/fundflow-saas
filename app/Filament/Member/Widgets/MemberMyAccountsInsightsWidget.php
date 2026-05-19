<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Services\MemberPortalAccountsInsightsService;
use Filament\Widgets\Widget;

class MemberMyAccountsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-my-accounts-insights';

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, mixed>|null */
    protected ?array $insightsData = null;

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->insightsData === null) {
            $this->insightsData = app(MemberPortalAccountsInsightsService::class)->snapshot();
        }

        return $this->insightsData;
    }
}
