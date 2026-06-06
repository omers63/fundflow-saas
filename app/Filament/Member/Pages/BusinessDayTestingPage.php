<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Support\BusinessDay;
use App\Support\BusinessDaySettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BusinessDayTestingPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Business calendar (testing)';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SETTINGS;

    protected static ?int $navigationSort = MemberNavigation::SORT_BUSINESS_DAY_TEST;

    protected static ?string $slug = 'business-calendar-testing';

    protected string $view = 'filament.member.pages.business-day-testing';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Business calendar (testing)');
    }

    public function getSubheading(): ?string
    {
        return __('Override the date the application treats as today. For QA only — hide this page before production.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'business_day' => BusinessDaySettings::forForm(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make(__('Business calendar'))
                    ->description(__('Leave empty to use the real calendar date. Time of day still follows the server clock.'))
                    ->schema([
                        DatePicker::make('business_day')
                            ->label(__('Current business day'))
                            ->native(false)
                            ->placeholder(__('Use real calendar date')),
                        Placeholder::make('business_day_effective')
                            ->label(__('Effective today'))
                            ->content(function (Get $get): string {
                                $configured = $get('business_day');

                                if (filled($configured)) {
                                    return __('App date: :business · Calendar: :calendar', [
                                        'business' => Carbon::parse((string) $configured)->toFormattedDateString(),
                                        'calendar' => BusinessDay::calendarToday()->toFormattedDateString(),
                                    ]);
                                }

                                return BusinessDay::calendarToday()->toFormattedDateString();
                            }),
                        Actions::make([
                            Action::make('save')
                                ->label(__('Save business day'))
                                ->icon('heroicon-o-check-circle')
                                ->color('primary')
                                ->action(fn () => $this->saveBusinessDay()),
                            Action::make('clear')
                                ->label(__('Use real calendar date'))
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->action(fn () => $this->clearBusinessDay()),
                        ]),
                    ]),
            ]);
    }

    public function saveBusinessDay(): void
    {
        $state = $this->form->getState();
        BusinessDaySettings::saveFromForm($state['business_day'] ?? null);

        Notification::make()
            ->title(__('Business day updated'))
            ->success()
            ->send();
    }

    public function clearBusinessDay(): void
    {
        BusinessDaySettings::saveFromForm(null);

        $this->form->fill(['business_day' => null]);

        Notification::make()
            ->title(__('Business day override cleared'))
            ->success()
            ->send();
    }
}
