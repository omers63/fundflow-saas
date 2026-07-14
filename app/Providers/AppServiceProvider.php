<?php

namespace App\Providers;

use App\Events\DatabaseNotificationsSentNow;
use App\Filament\Infolists\Components\TextEntry as AppTextEntry;
use App\Filament\Support\Action as AppAction;
use App\Filament\Support\MemberTableColumns;
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
use App\Filament\Tenant\Support\TenantPortalActionModal;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Http\Responses\FilamentLogoutResponse;
use App\Listeners\ApplyMemberNotificationLocaleListener;
use App\Listeners\LogNotificationDeliveryListener;
use App\Listeners\LogWebPushDeliveryListener;
use App\Listeners\RecordSystemJobRunListener;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Transaction;
use App\Observers\LoanInstallmentObserver;
use App\Observers\TransactionObserver;
use App\Session\WallClockDatabaseSessionHandler;
use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\ViewAction;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\Events\NotificationFailed as WebPushNotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent as WebPushNotificationSent;

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

        $this->app->afterResolving('session', function (SessionManager $manager): void {
            $manager->extend('database', function (): WallClockDatabaseSessionHandler {
                $config = config('session');
                $connectionName = $config['connection'] ?? null;

                return new WallClockDatabaseSessionHandler(
                    $this->app['db']->connection($connectionName),
                    $config['table'],
                    $config['lifetime'],
                    $this->app,
                );
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::anonymousComponentPath(
            resource_path('views/components/member-portal'),
            'member',
        );

        LoanInstallment::observe(LoanInstallmentObserver::class);
        Transaction::observe(TransactionObserver::class);

        Event::listen(CommandStarting::class, [RecordSystemJobRunListener::class, 'handleStarting']);
        Event::listen(CommandFinished::class, [RecordSystemJobRunListener::class, 'handleFinished']);

        Event::listen(NotificationSending::class, [ApplyMemberNotificationLocaleListener::class, 'handleSending']);

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'database' || ! Filament::hasBroadcasting() || ! config('filament.broadcasting.echo')) {
                return;
            }

            $notifiable = $event->notifiable;

            if ($notifiable instanceof Model || $notifiable instanceof Authenticatable) {
                DatabaseNotificationsSentNow::dispatch($notifiable);
            }
        });

        Event::listen(NotificationSent::class, [LogNotificationDeliveryListener::class, 'handleSent']);
        Event::listen(NotificationSent::class, [ApplyMemberNotificationLocaleListener::class, 'handleSent']);
        Event::listen(NotificationFailed::class, [LogNotificationDeliveryListener::class, 'handleFailed']);
        Event::listen(NotificationFailed::class, [ApplyMemberNotificationLocaleListener::class, 'handleFailed']);

        Event::listen(WebPushNotificationSent::class, [LogWebPushDeliveryListener::class, 'handleSent']);
        Event::listen(WebPushNotificationFailed::class, [LogWebPushDeliveryListener::class, 'handleFailed']);

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

                if (
                    ArabicDisplaySettings::enhancedNameStyle()
                    && ArabicTypography::isPersonNameColumn($column->getName())
                ) {
                    $column = MemberTableColumns::applyArabicNameTypography($column);
                }

                if (
                    Filament::getCurrentPanel()?->getId() === 'tenant'
                    && MemberTableColumns::shouldLinkColumn($column->getName())
                ) {
                    $column = MemberTableColumns::applyMemberLinkToTextColumn($column);
                }
            }

            return $column;
        });

        TextEntry::configureUsing(function (TextEntry $entry): TextEntry {
            $textSize = Filament::getCurrentPanel()?->getId() === 'member'
                ? TextSize::Small
                : TextSize::ExtraSmall;

            $entry = $entry
                ->wrap()
                ->size($textSize);

            if (
                ArabicDisplaySettings::enhancedNameStyle()
                && ArabicTypography::isPersonNameColumn($entry->getName())
            ) {
                $entry = $entry
                    ->html()
                    ->formatStateUsing(
                        fn ($state): Htmlable => ArabicTypography::display(
                            is_scalar($state) ? (string) $state : null,
                        ),
                    );
            }

            if (
                Filament::getCurrentPanel()?->getId() === 'tenant'
                && MemberTableColumns::shouldLinkColumn($entry->getName())
            ) {
                $entryName = $entry->getName();

                $entry = $entry->url(
                    fn (mixed $record): ?string => MemberTableColumns::resolveMemberUrl($entryName, $record),
                );
            }

            return $entry;
        });

        Field::configureUsing(fn (Field $field): Field => $field->translateLabel());

        FilamentAction::configureUsing(function (FilamentAction $action): FilamentAction {
            $action = $action->translateLabel();

            if ($action instanceof ViewAction) {
                return TenantPortalViewModal::apply($action);
            }

            if ($action->isConfirmationRequired()) {
                return TenantPortalActionModal::applyConfirmation($action);
            }

            $action = TenantPortalViewModal::applyToForm($action);

            if (TenantPortalActionModal::shouldShowProgress($action)) {
                return TenantPortalActionModal::applyProgressFooter($action);
            }

            return $action;
        });

        BaseFilter::configureUsing(fn (BaseFilter $filter): BaseFilter => $filter->translateLabel());

        Fieldset::configureUsing(fn (Fieldset $fieldset): Fieldset => $fieldset->translateLabel());

        Tab::configureUsing(function (Tab $tab): Tab {
            $color = TabLabelColors::forLabel($tab->getLabel());
            $tab = $tab
                ->translateLabel()
                ->extraAttributes(['data-ff-tab-color' => $color], merge: true)
                ->badgeColor($color)
                ->icon($tab->getIcon() ?? UiLabelIcons::forTab(label: $tab->getLabel()));

            return $tab;
        });

        Tabs::configureUsing(fn (Tabs $tabs): Tabs => $tabs->translateLabel());

        Table::configureUsing(function (Table $table): Table {
            return TableSummaryFooter::applyToTable(
                $table
                    ->striped()
                    ->selectable()
                    ->columnManager()
                    ->stackedOnMobile(),
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
            TextEntry::class => AppTextEntry::class,
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
