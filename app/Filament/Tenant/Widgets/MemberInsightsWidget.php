<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Concerns\InteractsWithUnfoldedSections;
use App\Services\MemberInsightsService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class MemberInsightsWidget extends Widget
{
    use InteractsWithUnfoldedSections;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected string $view = 'filament.tenant.widgets.member-insights';

    protected int|string|array $columnSpan = 'full';

    #[On('refresh-member-insights')]
    public function refreshInsights(): void
    {
        // Re-render with a fresh snapshot after roster / arrears mutations.
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberInsightsService::class)->snapshot();
    }
}
