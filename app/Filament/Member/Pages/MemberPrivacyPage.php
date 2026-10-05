<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Pages\Page;
use App\Filament\Support\TableHeaderIconAction;
use App\Models\Tenant\MemberPrivacyRequest;
use App\Services\Privacy\MemberPrivacyService;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class MemberPrivacyPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?string $navigationLabel = 'Privacy & data';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'privacy';

    protected string $view = 'filament.member.pages.member-privacy';

    public ?int $lastExportId = null;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Privacy & data');
    }

    public function getSubheading(): ?string
    {
        return __('Download a copy of your data or request account closure under PDPL.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();

        return [
            'requests' => $member
                ? MemberPrivacyRequest::query()->where('member_id', $member->id)->latest('id')->limit(10)->get()
                : collect(),
            'lastExportId' => $this->lastExportId,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('download_my_data')
                    ->label(__('Download my data'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (): void {
                        $member = CurrentMember::get();
                        if ($member === null) {
                            return;
                        }

                        $request = app(MemberPrivacyService::class)->requestExport($member);
                        $this->lastExportId = $request->id;

                        Notification::make()
                            ->title(__('Export ready'))
                            ->body(__('Your data export is ready to download.'))
                            ->success()
                            ->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('request_closure')
                    ->label(__('Request account closure'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->required()
                            ->rows(3),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data): void {
                        $member = CurrentMember::get();
                        if ($member === null) {
                            return;
                        }

                        try {
                            app(MemberPrivacyService::class)->requestClosure($member, (string) $data['reason']);
                            Notification::make()
                                ->title(__('Closure request submitted'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Could not submit'))->body($e->getMessage())->danger()->send();
                        }
                    }),
            ),
        ];
    }

    public function downloadExport(int $requestId): ?StreamedResponse
    {
        $member = CurrentMember::get();
        if ($member === null) {
            return null;
        }

        $request = MemberPrivacyRequest::query()
            ->whereKey($requestId)
            ->where('member_id', $member->id)
            ->where('type', MemberPrivacyRequest::TYPE_DATA_EXPORT)
            ->first();

        if ($request === null || blank($request->export_path) || ! Storage::disk('local')->exists($request->export_path)) {
            Notification::make()->title(__('Export not found'))->danger()->send();

            return null;
        }

        return Storage::disk('local')->download(
            $request->export_path,
            'my-data-'.$member->member_number.'.json',
        );
    }
}
