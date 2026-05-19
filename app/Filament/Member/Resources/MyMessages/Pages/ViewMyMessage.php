<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Pages;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Models\Tenant\DirectMessage;
use App\Services\Tenant\DirectMessagingService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

class ViewMyMessage extends ViewRecord
{
    protected static string $resource = MyMessageResource::class;

    protected string $view = 'filament.member.pages.view-my-message';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $memberUserId = auth('tenant')->id();

        if ($memberUserId !== null) {
            app(DirectMessagingService::class)->markMemberThreadRead($this->record, (int) $memberUserId);
        }
    }

    public function getHeading(): string
    {
        return $this->record->subject ?: __('Message thread');
    }

    /**
     * @return Collection<int, DirectMessage>
     */
    public function getThreadMessages(): Collection
    {
        $root = $this->record->parent_id ? $this->record->parent : $this->record;

        return collect([$root])->merge($root->replies()->with(['sender', 'recipient'])->get());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label(__('Reply'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->schema([
                    Textarea::make('body')
                        ->label(__('Reply'))
                        ->required()
                        ->rows(4)
                        ->maxLength(3000),
                    FileUpload::make('attachments')
                        ->label(__('Attachments'))
                        ->multiple()
                        ->disk('public')
                        ->directory('direct-messages')
                        ->openable()
                        ->downloadable()
                        ->maxFiles(5),
                ])
                ->action(function (array $data): void {
                    $memberUser = auth('tenant')->user();
                    $root = $this->record->parent_id ? $this->record->parent : $this->record;
                    $messaging = app(DirectMessagingService::class);

                    if ($memberUser === null) {
                        return;
                    }

                    $admin = $messaging->resolveAdminRecipientForThread($root, (int) $memberUser->id);

                    if ($admin === null) {
                        Notification::make()
                            ->title(__('Unable to send reply'))
                            ->body(__('No administrator is available to receive messages.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $attachments = is_array($data['attachments'] ?? null)
                        ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                        : [];

                    $messaging->sendMemberToAdmin(
                        $memberUser,
                        $admin,
                        $data['body'],
                        $attachments,
                        replyToRoot: $root,
                    );

                    $messaging->notifyAdminsOfMemberReply(
                        $memberUser,
                        $root->subject ?: __('Message'),
                        $data['body'],
                    );

                    Notification::make()
                        ->title(__('Reply sent'))
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl(['record' => $this->record]));
                }),
        ];
    }
}
