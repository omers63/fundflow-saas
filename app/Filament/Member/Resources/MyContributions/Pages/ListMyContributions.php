<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyContributions\Pages;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Support\MemberContributionFilamentActions;
use App\Services\MemberContributionInsightsService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;

class ListMyContributions extends ListRecords
{
    protected static string $resource = MyContributionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-member-contributions'];
    }

    public function getSubheading(): ?string
    {
        return __('Track your monthly cycles, posting status, cash readiness, and payment history.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.member.partials.my-contributions-stats')
                ->viewData(fn (): array => [
                    'cards' => app(MemberContributionInsightsService::class)->statCards(),
                ]),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            MemberContributionFilamentActions::requestOpenCycleAmount(),
            MemberContributionFilamentActions::applyOpenPeriodContribution(),
        ];
    }
}
