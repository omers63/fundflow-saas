<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\User;
use App\Models\Tenant\WebhookDelivery;
use App\Models\Tenant\WebhookEndpoint;
use App\Support\Billing\TenantFeatureGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class ApiIntegrationsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'API & webhooks';

    protected static ?int $navigationSort = TenantNavigation::SORT_NOTIFICATION_LOGS + 1;

    protected static ?string $slug = 'api-integrations';

    protected string $view = 'filament.tenant.pages.api-integrations';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true
            && app(TenantFeatureGate::class)->allows(TenantFeatureGate::FEATURE_API);
    }

    public function getTitle(): string|Htmlable
    {
        return __('API & webhooks');
    }

    public function getSubheading(): ?string
    {
        return __('Issue read API tokens and manage signed outbound webhook deliveries.');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(WebhookDelivery::query()->with('endpoint')->latest('id'))
                    ->columns([
                        TextColumn::make('id')->label(__('Delivery'))->sortable(),
                        TextColumn::make('endpoint.name')->label(__('Endpoint'))->wrap(),
                        TextColumn::make('event')->badge()->searchable(),
                        TextColumn::make('status')->badge()
                            ->color(fn (string $state): string => match ($state) {
                                WebhookDelivery::STATUS_DELIVERED => 'success',
                                WebhookDelivery::STATUS_FAILED => 'danger',
                                default => 'warning',
                            }),
                        TextColumn::make('attempt')->label(__('Attempt'))->numeric(),
                        TextColumn::make('http_status')->label(__('HTTP')),
                        TextColumn::make('error')->limit(40)->toggleable(),
                        TextColumn::make('created_at')->dateTime()->sortable(),
                        TextColumn::make('delivered_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                    ])
                    ->filters([
                        SelectFilter::make('status')->options([
                            WebhookDelivery::STATUS_PENDING => __('Pending'),
                            WebhookDelivery::STATUS_DELIVERED => __('Delivered'),
                            WebhookDelivery::STATUS_FAILED => __('Failed'),
                        ]),
                        SelectFilter::make('event')->options(
                            collect(WebhookEndpoint::EVENTS)->mapWithKeys(fn (string $e) => [$e => $e])->all(),
                        ),
                        DateColumnRangeFilter::make('created_at', __('Created')),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [],
            ),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false),
            ],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('create_token')
                    ->label(__('Create API token'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                        CheckboxList::make('abilities')
                            ->label(__('Abilities'))
                            ->options([
                                'members:read' => __('Members read'),
                                'balances:read' => __('Balances read'),
                                'contributions:read' => __('Contributions read'),
                                'loans:read' => __('Loans read'),
                                '*' => __('Full read (*)'),
                            ])
                            ->default(['*']),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth('tenant')->user();
                        $issued = $user->issueApiToken(
                            (string) $data['name'],
                            $data['abilities'] ?: ['*'],
                        );

                        Notification::make()
                            ->title(__('API token created'))
                            ->body(__('Copy this token now; it will not be shown again: :token', [
                                'token' => $issued['plain_text'],
                            ]))
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('create_endpoint')
                    ->label(__('Add webhook'))
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                        TextInput::make('url')->label(__('URL'))->url()->required()->maxLength(500),
                        CheckboxList::make('events')
                            ->label(__('Events'))
                            ->options(collect(WebhookEndpoint::EVENTS)->mapWithKeys(fn (string $e) => [$e => $e])->all())
                            ->helperText(__('Leave empty to receive all events.')),
                        Toggle::make('is_active')->label(__('Active'))->default(true),
                    ])
                    ->action(function (array $data): void {
                        $endpoint = WebhookEndpoint::query()->create([
                            'name' => $data['name'],
                            'url' => $data['url'],
                            'events' => $data['events'] ?? [],
                            'is_active' => (bool) ($data['is_active'] ?? true),
                            'created_by' => auth('tenant')->id(),
                        ]);

                        Notification::make()
                            ->title(__('Webhook endpoint created'))
                            ->body(__('Signing secret: :secret', ['secret' => $endpoint->secret]))
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ),
        ];
    }
}
