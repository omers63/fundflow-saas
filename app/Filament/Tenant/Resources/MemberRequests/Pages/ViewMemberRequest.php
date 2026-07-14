<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Pages;

use App\Filament\Support\MemberFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\MemberRequests\Schemas\MemberRequestViewInfolist;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ViewMemberRequest extends ViewRecord
{
    protected static string $resource = MemberRequestResource::class;

    public function getTitle(): string
    {
        return __('Member request');
    }

    public function getHeading(): string|Htmlable
    {
        return MemberRequest::typeLabel($this->record->type);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $requester = $this->record->requester?->name ?? __('Unknown member');
        $status = MemberRequest::statusOptions()[$this->record->status] ?? $this->record->status;

        return match ($this->record->status) {
            MemberRequest::STATUS_PENDING => __(':member · :status — review the payload, then approve or reject.', [
                'member' => $requester,
                'status' => $status,
            ]),
            default => __(':member · :status', [
                'member' => $requester,
                'status' => $status,
            ]),
        };
    }

    public function getContentTabLabel(): ?string
    {
        return __('Details');
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ff-tenant-member-request-detail',
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return MemberRequestViewInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewMember')
                ->label(__('Open member'))
                ->icon('heroicon-o-user')
                ->color('gray')
                ->visible(fn (): bool => $this->record->requester !== null)
                ->url(fn (): ?string => $this->record->requester
                    ? MemberTableColumns::memberRecordUrl($this->record->requester)
                    : null),
            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->isPending())
                ->requiresConfirmation()
                ->modalHeading(__('Approve this request?'))
                ->modalDescription(fn (): string => match ($this->record->type) {
                    MemberRequest::TYPE_ADD_DEPENDENT => __('Review the dependent details (and new parent email if provided), then approve when the household link is complete.'),
                    MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => __('The member will be marked withdrawn and portal access will end.'),
                    MemberRequest::TYPE_FREEZE_MEMBERSHIP => __('The member will be marked inactive until unfrozen.'),
                    MemberRequest::TYPE_UNFREEZE_MEMBERSHIP => __('The member will be set to active. Portal access stays blocked while arrears remain.'),
                    MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION => __('This cycle’s contribution due will be replaced with the requested amount. The member’s standing monthly allocation stays unchanged.'),
                    default => __('The change will be applied immediately for supported request types.'),
                })
                ->schema(function (): array {
                    return match ($this->record->type) {
                        MemberRequest::TYPE_FREEZE_MEMBERSHIP => [
                            MemberFilamentActions::freezeDateField(),
                        ],
                        MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => [
                            MemberFilamentActions::withdrawDateField(),
                        ],
                        default => [],
                    };
                })
                ->action(function (array $data): void {
                    try {
                        app(MemberRequestService::class)->approve(
                            $this->record,
                            auth('tenant')->user(),
                            $data,
                        );
                        Notification::make()->title(__('Request approved'))->success()->send();
                        $this->refreshResolvedRecord();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('Cannot approve'))
                            ->body(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('reject')
                ->label(__('Reject'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isPending())
                ->schema([
                    Textarea::make('admin_note')
                        ->label(__('Note to member (optional)'))
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    app(MemberRequestService::class)->reject(
                        $this->record,
                        auth('tenant')->user(),
                        $data['admin_note'] ?? null,
                    );
                    Notification::make()->title(__('Request rejected'))->success()->send();
                    $this->refreshResolvedRecord();
                }),
            DeleteAction::make(),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var MemberRequest $record */
        $record = parent::resolveRecord($key);

        return $record->load(['requester', 'reviewedBy']);
    }
}
