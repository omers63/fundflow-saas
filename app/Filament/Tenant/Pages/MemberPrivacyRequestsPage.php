<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\MemberPrivacyRequest;
use App\Models\Tenant\User;
use App\Services\Privacy\MemberPrivacyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use UnitEnum;

class MemberPrivacyRequestsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Privacy requests';

    protected static ?int $navigationSort = TenantNavigation::SORT_SETTINGS - 1;

    protected static ?string $slug = 'privacy-requests';

    protected string $view = 'filament.tenant.pages.member-privacy-requests';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Privacy requests');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(MemberPrivacyRequest::query()->with('member')->latest('id'))
                    ->columns([
                        TextColumn::make('id')->sortable(),
                        TextColumn::make('member.name')->label(__('Member'))->searchable(),
                        TextColumn::make('type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => MemberPrivacyRequest::typeLabels()[$state] ?? $state),
                        TextColumn::make('status')->badge(),
                        TextColumn::make('reason')->limit(40)->toggleable(),
                        TextColumn::make('created_at')->dateTime()->sortable(),
                        TextColumn::make('completed_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                    ])
                    ->filters([
                        SelectFilter::make('status')->options([
                            MemberPrivacyRequest::STATUS_PENDING => __('Pending'),
                            MemberPrivacyRequest::STATUS_APPROVED => __('Approved'),
                            MemberPrivacyRequest::STATUS_REJECTED => __('Rejected'),
                            MemberPrivacyRequest::STATUS_COMPLETED => __('Completed'),
                        ]),
                        SelectFilter::make('type')->options(MemberPrivacyRequest::typeLabels()),
                        DateColumnRangeFilter::make('created_at', __('Created')),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [
                    Action::make('approve')
                        ->label(__('Approve & anonymize'))
                        ->icon(Heroicon::OutlinedCheck)
                        ->color('danger')
                        ->visible(fn (MemberPrivacyRequest $record): bool => $record->type === MemberPrivacyRequest::TYPE_ACCOUNT_CLOSURE
                            && $record->status === MemberPrivacyRequest::STATUS_PENDING)
                        ->schema([
                            Textarea::make('admin_notes')->label(__('Admin notes'))->rows(2),
                        ])
                        ->requiresConfirmation()
                        ->action(function (MemberPrivacyRequest $record, array $data): void {
                            /** @var User $admin */
                            $admin = auth('tenant')->user();
                            try {
                                app(MemberPrivacyService::class)->approveClosure(
                                    $record,
                                    $admin,
                                    $data['admin_notes'] ?? null,
                                );
                                Notification::make()->title(__('Member anonymized'))->success()->send();
                                $this->resetTable();
                            } catch (InvalidArgumentException $e) {
                                Notification::make()->title(__('Could not approve'))->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon(Heroicon::OutlinedXMark)
                        ->visible(fn (MemberPrivacyRequest $record): bool => $record->status === MemberPrivacyRequest::STATUS_PENDING)
                        ->schema([
                            Textarea::make('admin_notes')->label(__('Admin notes'))->rows(2),
                        ])
                        ->requiresConfirmation()
                        ->action(function (MemberPrivacyRequest $record, array $data): void {
                            /** @var User $admin */
                            $admin = auth('tenant')->user();
                            app(MemberPrivacyService::class)->reject($record, $admin, $data['admin_notes'] ?? null);
                            Notification::make()->title(__('Request rejected'))->success()->send();
                            $this->resetTable();
                        }),
                ],
            ),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false),
            ],
        );
    }
}
