<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\Tenant\SupportRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

final class ManageSupportRequestAction
{
    public static function make(): Action
    {
        return TenantPortalViewModal::applyToForm(
            Action::make('manageSupportRequest')
                ->label(__('Manage'))
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('primary')
                ->modalHeading(fn (SupportRequest $record): string => __('Support request #:id', ['id' => $record->id]))
                ->modalDescription(fn (SupportRequest $record): string => $record->subject)
                ->modalWidth('3xl')
                ->fillForm(fn (SupportRequest $record): array => [
                    'status' => $record->status,
                    'reply' => '',
                    'notify_member' => true,
                ])
                ->schema([
                    Select::make('status')
                        ->label(__('Status'))
                        ->options(SupportRequest::statusOptions())
                        ->required()
                        ->native(false),
                    Toggle::make('escalated')
                        ->label(__('Escalated for supervisor attention'))
                        ->default(fn (SupportRequest $record): bool => $record->isEscalated())
                        ->dehydrated(false),
                    Textarea::make('reply')
                        ->label(__('Admin reply'))
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText(__('Sending a reply also posts to the member’s message thread when they have portal access.')),
                    Toggle::make('notify_member')
                        ->label(__('Deliver reply to member inbox'))
                        ->default(true),
                ])
                ->modalContent(fn (SupportRequest $record) => TenantPortalViewModal::content(
                    self::detailSections($record),
                ))
                ->action(function (SupportRequest $record, array $data): void {
                    $admin = auth('tenant')->user();

                    if (! $admin instanceof User) {
                        return;
                    }

                    $workflow = app(SupportRequestWorkflowService::class);
                    $status = (string) ($data['status'] ?? SupportRequest::STATUS_OPEN);

                    if ($status !== $record->status) {
                        $workflow->updateStatus($record, $status, $admin);
                        $record->refresh();
                    }

                    $escalated = (bool) ($data['escalated'] ?? false);

                    if ($escalated && ! $record->isEscalated()) {
                        $workflow->escalate($record);
                    } elseif (! $escalated && $record->isEscalated()) {
                        $workflow->clearEscalation($record);
                    }

                    $reply = trim((string) ($data['reply'] ?? ''));

                    if ($reply !== '') {
                        $workflow->addReply(
                            $record->fresh(),
                            $admin,
                            $reply,
                            (bool) ($data['notify_member'] ?? true),
                        );
                    }

                    Notification::make()
                        ->title(__('Support request updated'))
                        ->success()
                        ->send();
                }),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function detailSections(SupportRequest $record): array
    {
        $record->loadMissing(['member', 'user', 'replies.user', 'assignedTo']);

        $sections = ViewSupportRequestAction::sections($record);

        $sections[0]['hero']['chip'] = SupportRequest::statusOptions()[$record->status] ?? $record->status;
        $sections[0]['hero']['chipVariant'] = match ($record->status) {
            SupportRequest::STATUS_OPEN => 'gray',
            SupportRequest::STATUS_IN_PROGRESS => 'sky',
            SupportRequest::STATUS_RESOLVED => 'green',
            SupportRequest::STATUS_CLOSED => 'gray',
            default => 'gray',
        };

        $workflowItems = [
            ['label' => __('Status'), 'value' => SupportRequest::statusOptions()[$record->status] ?? $record->status],
            ['label' => __('SLA'), 'value' => app(SupportRequestWorkflowService::class)->slaLabel($record)],
            ['label' => __('Assigned to'), 'value' => $record->assignedTo?->name ?? __('—')],
            ['label' => __('Escalated'), 'value' => $record->isEscalated() ? __('Yes') : __('No')],
        ];

        if ($record->resolved_at !== null) {
            $workflowItems[] = [
                'label' => __('Resolved at'),
                'value' => $record->resolved_at->locale(app()->getLocale())->translatedFormat('d M Y H:i'),
            ];
        }

        $sections[] = [
            'title' => __('Workflow'),
            'columns' => 2,
            'items' => $workflowItems,
        ];

        if ($record->replies->isNotEmpty()) {
            $sections[] = [
                'title' => __('Replies'),
                'prose' => $record->replies
                    ->map(fn ($reply): string => ($reply->user?->name ?? __('Admin'))
                        .' · '
                        .($reply->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? '')
                        ."\n"
                        .$reply->body)
                    ->implode("\n\n---\n\n"),
            ];
        }

        return $sections;
    }
}
