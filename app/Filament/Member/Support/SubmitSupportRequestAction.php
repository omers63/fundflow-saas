<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\AdminNotificationActions;
use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class SubmitSupportRequestAction
{
    public static function make(string $name = 'submit_request'): Action
    {
        return MemberPortalViewModal::applyToForm(
            Action::make($name)
                ->label(__('Submit request'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading(__('Submit support request'))
                ->modalDescription(__('Send a message to fund administrators. They will be notified in the admin panel.'))
                ->schema([
                    Select::make('category')
                        ->label(__('Category'))
                        ->options(SupportRequest::categoryOptions())
                        ->required()
                        ->native(false),
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->required()
                        ->maxLength(150),
                    Textarea::make('message')
                        ->label(__('Message'))
                        ->required()
                        ->rows(5)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $user = auth('tenant')->user();
                    $member = CurrentMember::get();

                    if ($user === null) {
                        return;
                    }

                    $supportRequest = SupportRequest::query()->create([
                        'user_id' => $user->id,
                        'member_id' => $member?->id,
                        'category' => $data['category'],
                        'subject' => $data['subject'],
                        'message' => $data['message'],
                    ]);

                    $memberInfo = $member !== null
                        ? "{$user->name} (#{$member->member_number})"
                        : $user->name;

                    User::query()
                        ->where('is_admin', true)
                        ->each(function (User $admin) use ($data, $memberInfo, $supportRequest): void {
                            RecipientDatabaseNotification::send($admin, function (Notification $notification) use ($data, $memberInfo, $supportRequest): void {
                                $body = __('Request #:id from :from', [
                                    'id' => $supportRequest->id,
                                    'from' => $memberInfo,
                                ])
                                    ."\n".__('Category: :category', ['category' => SupportRequest::categoryLabel($data['category'])])
                                    ."\n\n".$data['message'];

                                $notification
                                    ->title(__('Support request #:id: :subject', [
                                        'id' => $supportRequest->id,
                                        'subject' => $data['subject'],
                                    ]))
                                    ->body($body)
                                    ->icon('heroicon-o-chat-bubble-left-right')
                                    ->iconColor('warning')
                                    ->actions([
                                        AdminNotificationActions::reviewSupportRequest($supportRequest),
                                    ]);
                            });
                        });

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Fund administrators have been notified.'))
                        ->success()
                        ->send();
                }),
        );
    }
}
