<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Notifications\Tenant\Concerns\BuildsFundPostingDatabaseMessage;
use App\Support\Notifications\FundPostingNotificationFormatter;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class NewFundPostingNotification extends Notification
{
    use BuildsFundPostingDatabaseMessage;

    public function __construct(
        public FundPosting $fundPosting,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('New deposit request'))
            ->body(FundPostingNotificationFormatter::adminNewRequestBody($this->fundPosting))
            ->icon('heroicon-o-inbox-arrow-down')
            ->iconColor('warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review deposit'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = FundPostingResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);

        return str_starts_with($url, 'http') ? $url : URL::to($url);
    }
}
