<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant\Member;
use App\Services\MemberDetailInsightsService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class MemberDetailInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.member-detail-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public ?int $memberId = null;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    #[On('refresh-member-detail-insights')]
    public function refreshWidget(int $memberId): void
    {
        if ($this->memberId === null || $memberId !== $this->memberId) {
            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->memberId === null) {
            return [];
        }

        $member = Member::query()->find($this->memberId);

        if ($member === null) {
            return [];
        }

        return app(MemberDetailInsightsService::class)->snapshot($member);
    }
}
