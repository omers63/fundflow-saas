<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Pages;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Support\MemberPortalViewModal;
use App\Services\Tenant\DirectMessagingService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMyMessages extends ListRecords
{
    protected static string $resource = MyMessageResource::class;

    public function getSubheading(): ?string
    {
        return __('Secure messages between you and fund administrators.');
    }

    protected function getHeaderActions(): array
    {
        return [
            MemberPortalViewModal::applyToForm(
                Action::make('compose')
                    ->label(__('New message'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
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
            ),
        ];
    }
}
