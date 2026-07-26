<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\TenantDashboardService;
use Filament\Widgets\Widget;

class TenantDashboardWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected static ?int $sort = -100;

    protected string $view = 'filament.tenant.widgets.tenant-dashboard';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, bool>
     */
    public array $unfoldedSections = [];

    public function unfoldSection(string $section): void
    {
        $this->unfoldedSections = [
            ...$this->unfoldedSections,
            $section => true,
        ];
    }

    public function isSectionUnfolded(string $section): bool
    {
        return (bool) ($this->unfoldedSections[$section] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $service = app(TenantDashboardService::class);
        $data = $service->coreSnapshot();

        if ($this->isSectionUnfolded('analytics')) {
            $data = array_merge($data, $service->detailsSnapshot());
        }

        return $data;
    }
}
