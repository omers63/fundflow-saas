<?php

namespace App\Providers;

use App\Events\DatabaseNotificationsSentNow;
use App\Filament\Support\Action as AppAction;
use App\Filament\Support\TabLabelColors;
use App\Filament\Support\TableSummaryFooter;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tables\Columns\BadgeColumn as AppBadgeColumn;
use App\Filament\Tables\Columns\BooleanColumn as AppBooleanColumn;
use App\Filament\Tables\Columns\CheckboxColumn as AppCheckboxColumn;
use App\Filament\Tables\Columns\ColorColumn as AppColorColumn;
use App\Filament\Tables\Columns\ColumnGroup as AppColumnGroup;
use App\Filament\Tables\Columns\IconColumn as AppIconColumn;
use App\Filament\Tables\Columns\ImageColumn as AppImageColumn;
use App\Filament\Tables\Columns\SelectColumn as AppSelectColumn;
use App\Filament\Tables\Columns\TagsColumn as AppTagsColumn;
use App\Filament\Tables\Columns\TextColumn as AppTextColumn;
use App\Filament\Tables\Columns\TextInputColumn as AppTextInputColumn;
use App\Filament\Tables\Columns\ToggleColumn as AppToggleColumn;
use App\Filament\Tables\Columns\ViewColumn as AppViewColumn;
use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use App\Http\Responses\FilamentLogoutResponse;
use App\Listeners\RecordSystemJobRunListener;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Transaction;
use App\Observers\LoanInstallmentObserver;
use App\Observers\TransactionObserver;
use Filament\Actions\Action as FilamentAction;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerFilamentTableColumnHeaderBindings();

        $this->app->bind(FilamentAction::class, AppAction::class);

        $this->app->bind(
            LogoutResponse::class,
            FilamentLogoutResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LoanInstallment::observe(LoanInstallmentObserver::class);
        Transaction::observe(TransactionObserver::class);

        Event::listen(CommandStarting::class, [RecordSystemJobRunListener::class, 'handleStarting']);
        Event::listen(CommandFinished::class, [RecordSystemJobRunListener::class, 'handleFinished']);

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'database' || ! Filament::hasBroadcasting() || ! config('filament.broadcasting.echo')) {
                return;
            }

            $notifiable = $event->notifiable;

            if ($notifiable instanceof Model || $notifiable instanceof Authenticatable) {
                DatabaseNotificationsSentNow::dispatch($notifiable);
            }
        });

        Column::configureUsing(function (Column $column): Column {
            $column = $column
                ->toggleable()
                ->sortable()
                ->searchable()
                ->translateLabel()
                ->wrapHeader();

            if ($column instanceof TextColumn) {
                $textSize = Filament::getCurrentPanel()?->getId() === 'member'
                    ? TextSize::Small
                    : TextSize::ExtraSmall;

                $column = $column
                    ->wrap()
                    ->size($textSize);

                $column = TableSummaryFooter::applySummarizersToTextColumn($column);
            }

            return $column;
        });

        TextEntry::configureUsing(function (TextEntry $entry): TextEntry {
            $textSize = Filament::getCurrentPanel()?->getId() === 'member'
                ? TextSize::Small
                : TextSize::ExtraSmall;

            return $entry
                ->wrap()
                ->size($textSize);
        });

        Field::configureUsing(fn (Field $field): Field => $field->translateLabel());

        FilamentAction::configureUsing(fn (FilamentAction $action): FilamentAction => $action->translateLabel());

        BaseFilter::configureUsing(fn (BaseFilter $filter): BaseFilter => $filter->translateLabel());

        Fieldset::configureUsing(fn (Fieldset $fieldset): Fieldset => $fieldset->translateLabel());

        Tab::configureUsing(function (Tab $tab): Tab {
            $color = TabLabelColors::forLabel($tab->getLabel());
            $icon = $tab->getIcon() ?? UiLabelIcons::forLabel($tab->getLabel());

            $tab = $tab
                ->translateLabel()
                ->extraAttributes(['data-ff-tab-color' => $color], merge: true)
                ->badgeColor($color);

            if ($icon !== null) {
                $tab->icon($icon);
            }

            return $tab;
        });

        Tabs::configureUsing(fn (Tabs $tabs): Tabs => $tabs->translateLabel());

        Table::configureUsing(function (Table $table): Table {
            return TableSummaryFooter::applyToTable(
                $table->striped()->selectable()->columnManager(),
            );
        });
    }

    /**
     * Filament resolves columns via the container; binding allows global header label formatting.
     *
     * @see CapitalizesTableColumnHeaderLabel
     */
    private function registerFilamentTableColumnHeaderBindings(): void
    {
        $bindings = [
            TextColumn::class => AppTextColumn::class,
            BadgeColumn::class => AppBadgeColumn::class,
            TagsColumn::class => AppTagsColumn::class,
            IconColumn::class => AppIconColumn::class,
            BooleanColumn::class => AppBooleanColumn::class,
            CheckboxColumn::class => AppCheckboxColumn::class,
            ImageColumn::class => AppImageColumn::class,
            ColorColumn::class => AppColorColumn::class,
            ToggleColumn::class => AppToggleColumn::class,
            SelectColumn::class => AppSelectColumn::class,
            TextInputColumn::class => AppTextInputColumn::class,
            ViewColumn::class => AppViewColumn::class,
            ColumnGroup::class => AppColumnGroup::class,
        ];

        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }
}
