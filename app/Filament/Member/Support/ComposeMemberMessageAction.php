<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Services\Tenant\DirectMessagingService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class ComposeMemberMessageAction
{
    public static function make(string $name = 'compose'): Action
    {
        return MemberPortalViewModal::applyToForm(
            Action::make($name)
                ->label(__('New message'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => MyMessageResource::resolveAdminRecipient() !== null)
                ->modalHeading(__('Message administration'))
                ->schema([
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->required()
                        ->maxLength(150),
                    Textarea::make('body')
                        ->label(__('Message'))
                        ->required()
                        ->rows(5)
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
                    $messaging = app(DirectMessagingService::class);

                    if ($memberUser === null || $admin === null) {
                        Notification::make()
                            ->title(__('Unable to send message'))
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
                        $data['subject'],
                    );

                    $messaging->notifyAdminsOfMemberMessage($memberUser, $data['subject'], $data['body']);

                    Notification::make()
                        ->title(__('Message sent'))
                        ->success()
                        ->send();
                }),
        );
    }
}
