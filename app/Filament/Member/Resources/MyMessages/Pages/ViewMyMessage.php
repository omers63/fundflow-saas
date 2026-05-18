<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Pages;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\User;
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

        $memberUserId = (int) auth('tenant')->id();
        $rootId = $this->record->parent_id ?? $this->record->id;

        DirectMessage::query()
            ->where(function ($query) use ($rootId): void {
                $query->where('id', $rootId)->orWhere('parent_id', $rootId);
            })
            ->where('to_user_id', $memberUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
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
                    $admin = MyMessageResource::resolveAdminRecipient();
                    $root = $this->record->parent_id ? $this->record->parent : $this->record;

                    if ($memberUser === null || $admin === null) {
                        return;
                    }

                    $attachments = is_array($data['attachments'] ?? null)
                        ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                        : [];

                    DirectMessage::create([
                        'from_user_id' => $memberUser->id,
                        'to_user_id' => $admin->id,
                        'parent_id' => $root->id,
                        'subject' => $root->subject,
                        'body' => $data['body'],
                        'attachments' => $attachments,
                    ]);

                    User::query()->where('is_admin', true)->each(function (User $adminUser) use ($memberUser, $root, $data): void {
                        Notification::make()
                            ->title(__('Reply from :name', ['name' => $memberUser->name]))
                            ->body(($root->subject ?: __('Message')).': '.mb_strimwidth($data['body'], 0, 100, '...'))
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->iconColor('info')
                            ->sendToDatabase($adminUser);
                    });

                    Notification::make()
                        ->title(__('Reply sent'))
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl(['record' => $this->record]));
                }),
        ];
    }
}
