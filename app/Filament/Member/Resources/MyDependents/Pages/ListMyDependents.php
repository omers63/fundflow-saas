<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Pages;

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Services\MemberDependentsInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Livewire\Attributes\On;

class ListMyDependents extends ListRecords
{
    protected static string $resource = MyDependentResource::class;

    public int $dependentsInsightsVersion = 0;

    public function refreshDependentsInsights(): void
    {
        $this->dependentsInsightsVersion++;
    }

    #[On('refresh-member-dependents-insights')]
    public function refreshDependentsInsightsFromEvent(): void
    {
        $this->refreshDependentsInsights();
    }

    public function getSubheading(): ?string
    {
        $member = CurrentMember::get();

        if ($member?->dependents()->exists()) {
            return __('Manage funding and cash, then open a dependent’s portal to act on their behalf.');
        }

        return __('Use the actions above to request adding or removing dependents from your household.');
    }

    /**
     * @return array<string, mixed>
     */
    public function dependentsInsightsSnapshot(): array
    {
        return app(MemberDependentsInsightsService::class)->snapshot();
    }

    public function shouldShowDependentsInsights(): bool
    {
        $member = CurrentMember::get();

        return $member?->isParent() && $member->dependents()->exists();
    }

    public function content(Schema $schema): Schema
    {
        $components = [];

        if ($this->shouldShowDependentsInsights()) {
            $components[] = SchemaView::make('filament.member.widgets.partials.member-dependents-insights-body')
                ->viewData(fn (): array => [
                    'd' => $this->dependentsInsightsSnapshot(),
                    'insightsVersion' => $this->dependentsInsightsVersion,
                ]);
        }

        $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
        $components[] = EmbeddedTable::make();
        $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER);
        $components[] = SchemaView::make('filament.member.resources.my-dependents.pages.household-requests-panel');

        return $schema->components($components);
    }
}
