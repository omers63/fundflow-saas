<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Concerns\ManagesCommunicationsInbox;
use App\Filament\Tenant\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Filament\Tenant\Support\CommunicationsTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Services\Tenant\MemberAnnouncementService;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\BusinessDay;
use App\Support\CommunicationBrandSettings;
use App\Support\Lang;
use App\Support\NotificationTemplateCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Attributes\Url;
use UnitEnum;

class CommunicationsWorkspacePage extends Page implements HasTable
{
    use InteractsWithTable;
    use ManagesCommunicationsInbox;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Communications';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'communications';

    protected string $view = 'filament.tenant.pages.communications-workspace';

    /** @var 'inbox'|'announcements'|'templates'|'delivery' */
    #[Url(as: 'sideTab')]
    public string $sideTab = CommunicationsTabRegistry::TAB_INBOX;

    public ?string $selectedTemplateKey = null;

    /** @var 'email'|'in_app'|'sms_push' */
    public string $selectedChannelFamily = NotificationTemplate::FAMILY_EMAIL;

    public string $previewLocale = 'en';

    public string $previewText = '';

    public string $en_subject = '';

    public string $en_body = '';

    public string $ar_subject = '';

    public string $ar_body = '';

    public ?string $brand_from_name = null;

    public string $brand_primary_color = '#0f766e';

    public string $brand_footer_en = '';

    public string $brand_footer_ar = '';

    public ?string $brand_logo_path = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function getNavigationBadge(): ?string
    {
        return self::unreadInboxBadge();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('Communications');
    }

    public function getSubheading(): ?string
    {
        return match ($this->sideTab) {
            CommunicationsTabRegistry::TAB_INBOX => __('Member conversations and direct messages.'),
            CommunicationsTabRegistry::TAB_TEMPLATES => __('Edit EN/AR templates for email, in-app (bell), and push/SMS.'),
            CommunicationsTabRegistry::TAB_DELIVERY => __('Email, SMS, WhatsApp, and in-app delivery attempts.'),
            default => __('Compose announcements and review broadcast history.'),
        };
    }

    public function mount(): void
    {
        if ($this->sideTab === CommunicationsTabRegistry::TAB_SETTINGS) {
            $this->redirect(Settings::getUrl(['tab' => 'communication::tab']));

            return;
        }

        $this->sideTab = CommunicationsTabRegistry::normalize($this->sideTab);

        if (
            ! in_array($this->sideTab, [
                CommunicationsTabRegistry::TAB_INBOX,
                CommunicationsTabRegistry::TAB_ANNOUNCEMENTS,
                CommunicationsTabRegistry::TAB_TEMPLATES,
                CommunicationsTabRegistry::TAB_DELIVERY,
            ], true)
        ) {
            $this->sideTab = CommunicationsTabRegistry::TAB_INBOX;
        }

        if (
            $this->sideTab === CommunicationsTabRegistry::TAB_TEMPLATES
            && DatabaseSchema::hasTable('notification_templates')
        ) {
            NotificationTemplateCatalog::seedMissingDefaults();
            $this->selectedTemplateKey ??= array_key_first(NotificationTemplateCatalog::definitions());
            $this->loadTemplateFormState();
            $this->refreshPreview();
        }
    }

    public function table(Table $table): Table
    {
        return match ($this->sideTab) {
            CommunicationsTabRegistry::TAB_INBOX => $this->configureInboxTable($table),
            CommunicationsTabRegistry::TAB_DELIVERY => NotificationLogsTable::configure($table),
            default => $this->configureAnnouncementsTable($table),
        };
    }

    protected function configureAnnouncementsTable(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(MemberAnnouncement::query()->with('createdBy')->latest('id'))
            ->columns([
                TextColumn::make('title_en')
                    ->label(__('Title'))
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                TextColumn::make('audience')
                    ->label(__('Audience'))
                    ->formatStateUsing(fn (?string $state): string => MemberAnnouncement::audienceOptions()[$state] ?? ($state ?? '—')),
                TextColumn::make('channels')
                    ->label(__('Channels'))
                    ->formatStateUsing(function (mixed $state): string {
                        $channels = is_array($state) ? $state : [];

                        return collect($channels)
                            ->map(fn (string $channel): string => MemberAnnouncement::channelOptions()[$channel] ?? $channel)
                            ->implode(', ');
                    }),
                TextColumn::make('recipient_count')
                    ->label(__('Recipients'))
                    ->numeric(),
                TextColumn::make('delivered_count')
                    ->label(__('Delivered'))
                    ->numeric(),
                TextColumn::make('scheduled_for')
                    ->label(__('Scheduled'))
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('sent_at')
                    ->label(__('Sent'))
                    ->dateTime()
                    ->placeholder(__('Pending')),
                TextColumn::make('createdBy.name')
                    ->label(__('By'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('audience')
                    ->options(MemberAnnouncement::audienceOptions()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No announcements yet'))
            ->emptyStateDescription(__('Compose an announcement to notify members via the bell, email, or SMS.')), []);
    }

    protected function getHeaderActions(): array
    {
        return match ($this->sideTab) {
            CommunicationsTabRegistry::TAB_INBOX => $this->inboxHeaderActions(),
            CommunicationsTabRegistry::TAB_ANNOUNCEMENTS => [
                TenantPortalViewModal::applyToForm(
                    Action::make('compose_announcement')
                        ->label(__('Compose announcement'))
                        ->icon('heroicon-o-megaphone')
                        ->color('primary')
                        ->modalHeading(__('Compose member announcement'))
                        ->modalDescription(__('Broadcast a bilingual alert to members via in-app (bell), SMS, and/or email.'))
                        ->modalWidth('3xl')
                        ->schema($this->announcementFormSchema())
                        ->action(function (array $data): void {
                            $admin = auth('tenant')->user();

                            if (! $admin instanceof User) {
                                return;
                            }

                            try {
                                $announcement = app(MemberAnnouncementService::class)->createAndDispatch($admin, [
                                    'audience' => (string) ($data['audience'] ?? MemberAnnouncement::AUDIENCE_ALL_ACTIVE),
                                    'title_en' => (string) ($data['title_en'] ?? ''),
                                    'title_ar' => $data['title_ar'] ?? null,
                                    'body_en' => (string) ($data['body_en'] ?? ''),
                                    'body_ar' => $data['body_ar'] ?? null,
                                    'channels' => array_values($data['channels'] ?? []),
                                    'scheduled_for' => $data['scheduled_for'] ?? null,
                                ]);
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            if ($announcement->scheduled_for !== null && $announcement->sent_at === null) {
                                Notification::make()
                                    ->title(__('Announcement scheduled'))
                                    ->body(__('Scheduled for :at', ['at' => $announcement->scheduled_for->toDayDateTimeString()]))
                                    ->success()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('Announcement sent'))
                                ->body(__('Delivered to :count of :total member(s).', [
                                    'count' => $announcement->delivered_count,
                                    'total' => $announcement->recipient_count,
                                ]))
                                ->success()
                                ->send();

                            $this->resetTable();
                        }),
                ),
            ],
            default => [],
        };
    }

    /**
     * @return array<int, Select|TextInput|Textarea|CheckboxList|DateTimePicker>
     */
    protected function announcementFormSchema(): array
    {
        $announcements = app(MemberAnnouncementService::class);

        return [
            Select::make('audience')
                ->label(__('Recipients'))
                ->options(MemberAnnouncement::audienceOptions())
                ->default(MemberAnnouncement::AUDIENCE_ALL_ACTIVE)
                ->required()
                ->live()
                ->helperText(fn (?string $state): string => $state === null
                    ? ''
                    : __('Matches :count member(s) with portal accounts.', [
                        'count' => $announcements->previewCount($state),
                    ])),
            TextInput::make('title_en')
                ->label(__('Title (English)'))
                ->required()
                ->maxLength(150),
            TextInput::make('title_ar')
                ->label(__('Title (Arabic)'))
                ->maxLength(150),
            Textarea::make('body_en')
                ->label(__('Body (English)'))
                ->rows(4)
                ->required()
                ->maxLength(5000),
            Textarea::make('body_ar')
                ->label(__('Body (Arabic)'))
                ->rows(4)
                ->maxLength(5000),
            CheckboxList::make('channels')
                ->label(__('Delivery channels'))
                ->options(MemberAnnouncement::channelOptions())
                ->default([MemberAnnouncement::CHANNEL_IN_APP])
                ->required()
                ->columns(3),
            DateTimePicker::make('scheduled_for')
                ->label(__('Schedule for'))
                ->native(false)
                ->minDate(BusinessDay::now())
                ->helperText(__('Leave empty to send immediately.')),
        ];
    }

    public function selectTemplate(string $key): void
    {
        if (NotificationTemplateCatalog::definition($key) === null) {
            return;
        }

        $this->selectedTemplateKey = $key;
        $this->loadTemplateFormState();
        $this->refreshPreview();
    }

    public function selectChannelFamily(string $family): void
    {
        if (! in_array($family, NotificationTemplate::channelFamilies(), true)) {
            return;
        }

        $this->selectedChannelFamily = $family;
        $this->loadTemplateFormState();
        $this->refreshPreview();
    }

    public function saveTemplate(): void
    {
        if ($this->selectedTemplateKey === null) {
            return;
        }

        $key = $this->selectedTemplateKey;
        $family = $this->selectedChannelFamily;

        foreach (['en', 'ar'] as $locale) {
            $subject = $locale === 'en' ? $this->en_subject : $this->ar_subject;
            $body = $locale === 'en' ? $this->en_body : $this->ar_body;

            NotificationTemplate::query()->updateOrCreate(
                [
                    'key' => $key,
                    'locale' => $locale,
                    'channel_family' => $family,
                ],
                [
                    'subject' => filled($subject) ? $subject : null,
                    'body_markdown' => $body,
                ],
            );
        }

        if ($family === NotificationTemplate::FAMILY_EMAIL) {
            CommunicationBrandSettings::saveFromForm([
                'brand_from_name' => $this->brand_from_name,
                'brand_primary_color' => $this->brand_primary_color,
                'brand_footer_en' => $this->brand_footer_en,
                'brand_footer_ar' => $this->brand_footer_ar,
                'brand_logo_path' => $this->brand_logo_path,
            ]);
        }

        Notification::make()
            ->title(__('Template saved'))
            ->success()
            ->send();

        $this->refreshPreview();
    }

    public function restoreTemplateDefaults(): void
    {
        if ($this->selectedTemplateKey === null) {
            return;
        }

        NotificationTemplateCatalog::restoreDefaults($this->selectedTemplateKey);
        $this->loadTemplateFormState();
        $this->refreshPreview();

        Notification::make()
            ->title(__('Defaults restored'))
            ->success()
            ->send();
    }

    public function refreshPreview(): void
    {
        if ($this->selectedTemplateKey === null) {
            $this->previewText = '';

            return;
        }

        $definition = NotificationTemplateCatalog::definition($this->selectedTemplateKey);
        $sample = [];
        foreach ($definition['variables'] ?? [] as $variable) {
            $sample[$variable] = match ($variable) {
                'member_name' => 'Amina',
                'amount' => '1,250.00',
                'period' => 'July 2026',
                'deadline' => '20 Jul 2026',
                'balance' => '500.00',
                'loan_id' => '42',
                'sender_name' => 'Fund Admin',
                'preview' => 'Please review your statement.',
                'subject', 'title' => 'Sample subject',
                'body' => 'Sample body text for preview.',
                'action_url' => url('/'),
                default => $variable,
            };
        }

        $rendered = app(NotificationTemplateRenderer::class)->render(
            $this->selectedTemplateKey,
            $this->selectedChannelFamily,
            $this->previewLocale,
            $sample,
        );

        $this->previewText = trim($rendered['subject']."\n\n".$rendered['body']);
    }

    public function setPreviewLocale(string $locale): void
    {
        $this->previewLocale = $locale === 'ar' ? 'ar' : 'en';
        $this->refreshPreview();
    }

    protected function loadTemplateFormState(): void
    {
        if ($this->selectedTemplateKey === null) {
            return;
        }

        $en = $this->templateRow($this->selectedTemplateKey, 'en', $this->selectedChannelFamily);
        $ar = $this->templateRow($this->selectedTemplateKey, 'ar', $this->selectedChannelFamily);
        $brand = CommunicationBrandSettings::allForForm();

        $this->en_subject = (string) ($en['subject'] ?? '');
        $this->en_body = (string) ($en['body'] ?? '');
        $this->ar_subject = (string) ($ar['subject'] ?? '');
        $this->ar_body = (string) ($ar['body'] ?? '');
        $this->brand_from_name = $brand['brand_from_name'];
        $this->brand_primary_color = (string) ($brand['brand_primary_color'] ?? '#0f766e');
        $this->brand_footer_en = (string) ($brand['brand_footer_en'] ?? '');
        $this->brand_footer_ar = (string) ($brand['brand_footer_ar'] ?? '');
        $this->brand_logo_path = $brand['brand_logo_path'];
    }

    /**
     * @return array{subject: ?string, body: string}
     */
    protected function templateRow(string $key, string $locale, ?string $channelFamily = null): array
    {
        $family = $channelFamily ?? $this->selectedChannelFamily;

        if ($key === '' || ! DatabaseSchema::hasTable('notification_templates')) {
            $defaults = NotificationTemplateCatalog::defaultContent($key, $locale) ?? ['subject' => '', 'body' => ''];

            return ['subject' => $defaults['subject'], 'body' => $defaults['body']];
        }

        $row = NotificationTemplate::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('channel_family', $family)
            ->first();

        if ($row === null) {
            $defaults = NotificationTemplateCatalog::defaultContent($key, $locale) ?? ['subject' => '', 'body' => ''];

            return ['subject' => $defaults['subject'], 'body' => $defaults['body']];
        }

        return [
            'subject' => $row->subject,
            'body' => $row->body_markdown,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function channelFamilyOptions(): array
    {
        return [
            NotificationTemplate::FAMILY_EMAIL => __('Email'),
            NotificationTemplate::FAMILY_IN_APP => __('In-app (bell)'),
            NotificationTemplate::FAMILY_SMS_PUSH => __('Push / SMS'),
        ];
    }

    public function channelFamilySubjectLabel(): string
    {
        return match ($this->selectedChannelFamily) {
            NotificationTemplate::FAMILY_IN_APP => __('In-app title'),
            NotificationTemplate::FAMILY_SMS_PUSH => __('Push / SMS title'),
            default => __('Email subject'),
        };
    }

    public function channelFamilyBodyLabel(): string
    {
        return match ($this->selectedChannelFamily) {
            NotificationTemplate::FAMILY_SMS_PUSH => __('Push / SMS body'),
            NotificationTemplate::FAMILY_IN_APP => __('In-app body (Markdown)'),
            default => __('Email body (Markdown)'),
        };
    }

    public function channelFamilyHelperText(): string
    {
        $audience = $this->selectedTemplateKey !== null
            ? NotificationTemplateCatalog::audienceFor($this->selectedTemplateKey)
            : 'member';

        if ($audience === 'admin') {
            return match ($this->selectedChannelFamily) {
                NotificationTemplate::FAMILY_IN_APP => __('Shown in the admin bell for automation and operational alerts.'),
                NotificationTemplate::FAMILY_SMS_PUSH => __('Used for admin browser push. Keep it short; Markdown is stripped for delivery.'),
                default => __('Used when this admin alert is emailed (for example delinquency digest).'),
            };
        }

        return match ($this->selectedChannelFamily) {
            NotificationTemplate::FAMILY_IN_APP => __('Shown in the member bell and Alerts history.'),
            NotificationTemplate::FAMILY_SMS_PUSH => __('Used for web push, SMS, and WhatsApp. Keep it short; Markdown is stripped for delivery.'),
            default => __('Wrapped in the branded email layout. Brand chrome below applies to email only.'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function templateOptions(): array
    {
        $options = [];

        foreach (NotificationTemplateCatalog::definitions() as $key => $definition) {
            $options[$key] = Lang::formatUiLabel(__($definition['label']));
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function templateOptionGroups(): array
    {
        $grouped = NotificationTemplateCatalog::optionsGroupedByAudience();

        return [
            __('Members') => array_map(
                fn (string $label): string => Lang::formatUiLabel($label),
                $grouped['member'],
            ),
            __('Admin & automation') => array_map(
                fn (string $label): string => Lang::formatUiLabel($label),
                $grouped['admin'],
            ),
        ];
    }

    public function selectedTemplateVariables(): string
    {
        $definition = $this->selectedTemplateKey
            ? NotificationTemplateCatalog::definition($this->selectedTemplateKey)
            : null;

        return implode(', ', array_map(
            fn (string $v): string => '{{'.$v.'}}',
            $definition['variables'] ?? [],
        ));
    }
}
