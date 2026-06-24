<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Pages;

use App\Filament\Support\MembershipApplicationFilamentActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Widgets\MembershipApplicationReviewWidget;
use App\Models\Tenant\MembershipApplication;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EditMembershipApplication extends EditRecord
{
    protected static string $resource = MembershipApplicationResource::class;

    public function getTitle(): string
    {
        return match ($this->record->status) {
            'pending' => __('Review application'),
            default => __('Application'),
        };
    }

    public function getHeading(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $status = MembershipApplication::statusOptions()[$this->record->status] ?? $this->record->status;

        return match ($this->record->status) {
            'pending' => __(':email · :status — verify documents and fee transfer, then approve or reject.', [
                'email' => $this->record->email,
                'status' => $status,
            ]),
            default => __(':email · :status', [
                'email' => $this->record->email,
                'status' => $status,
            ]),
        };
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedClipboardDocumentCheck;
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        $classes = [
            ...parent::getPageClasses(),
            'ff-tenant-application-review',
        ];

        if ($this->record->status === 'pending') {
            $classes[] = 'ff-tenant-application-review--pending';
        }

        return $classes;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        if ($this->record->status !== 'pending') {
            return [];
        }

        return [
            MembershipApplicationReviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'record' => $this->record,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToMembers')
                ->label(__('Members'))
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(MemberResource::getUrl('index')),
            MembershipApplicationFilamentActions::approve(),
            MembershipApplicationFilamentActions::reject(),
            DeleteAction::make()
                ->after(fn () => MembershipApplicationResource::dispatchInsightsRefresh($this)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        MembershipApplicationResource::dispatchInsightsRefresh($this);
    }
}
