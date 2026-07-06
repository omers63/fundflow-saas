<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Pages;

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Resources\MyDependents\Support\MyDependentTableActions;
use App\Filament\Member\Widgets\MemberDependentsInsightsWidget;
use App\Models\Tenant\MemberRequest;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListMyDependents extends ListRecords
{
    protected static string $resource = MyDependentResource::class;

    #[Url(as: 'tab', except: 'dependents')]
    public string $activeSection = 'dependents';

    public function mount(): void
    {
        if (! in_array($this->activeSection, ['dependents', 'requests'], true)) {
            $this->activeSection = 'dependents';
        }

        parent::mount();
    }

    public function setActiveSection(string $section): void
    {
        if (! in_array($section, ['dependents', 'requests'], true)) {
            return;
        }

        $this->activeSection = $section;
        $this->resetPage();
    }

    public function getSubheading(): ?string
    {
        return match ($this->activeSection) {
            'requests' => __('Submit or track requests to add or remove dependents.'),
            default => __('Manage allocations and cash, then open a dependent’s portal to act on their behalf.'),
        };
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        if ($this->activeSection !== 'dependents') {
            return [];
        }

        return [
            MemberDependentsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        if ($this->activeSection !== 'dependents') {
            return [];
        }

        return MyDependentTableActions::headerActions();
    }

    protected function getTableQuery(): Builder
    {
        if ($this->activeSection !== 'dependents') {
            return parent::getTableQuery()->whereRaw('1 = 0');
        }

        return parent::getTableQuery();
    }

    /**
     * @return array<string, mixed>
     */
    public function getHubViewData(): array
    {
        $parent = CurrentMember::get();

        return [
            'activeSection' => $this->activeSection,
            'dependentsCount' => $parent?->dependents()->count() ?? 0,
            'pendingRequestsCount' => $parent !== null
                ? MemberRequest::query()
                    ->where('requester_member_id', $parent->id)
                    ->where('status', MemberRequest::STATUS_PENDING)
                    ->whereIn('type', [
                        MemberRequest::TYPE_ADD_DEPENDENT,
                        MemberRequest::TYPE_REMOVE_DEPENDENT,
                    ])
                    ->count()
                : 0,
        ];
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            SchemaView::make('filament.member.resources.my-dependents.pages.dependents-hub-shell')
                ->viewData(fn (): array => $this->getHubViewData()),
        ];

        if ($this->activeSection === 'dependents') {
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
            $components[] = EmbeddedTable::make();
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER);
        } else {
            $components[] = SchemaView::make('filament.member.resources.my-dependents.pages.household-requests-panel');
        }

        return $schema->components($components);
    }
}
