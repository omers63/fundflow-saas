<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Pages\MigrationWorkflowPage;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('migrationWorkflow')
                ->label(__('Migrations'))
                ->icon('heroicon-o-clock')
                ->url(MigrationWorkflowPage::getUrl()),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MemberInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Manage the member roster, household structure, status, and contribution commitments.');
    }
}
