<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Concerns\ManagesCommunicationsInbox;
use App\Filament\Tenant\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Filament\Tenant\Support\CommunicationsTabRegistry;
use App\Filament\Tenant\Support\SettingsTabRegistry;
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

    /** When true on viewports under lg, show the editor pane only. */
    public bool $templatesEditorFocus = false;

    public string $templateSearch = '';

    /** @var 'all'|'member'|'admin' */
    public string $templateAudienceFilter = 'all';

    /** @var 'en'|'ar' */
    public string $editLocale = 'en';

    public bool $compareLocales = false;

    public bool $previewExpanded = false;

    public bool $brandPanelOpen = false;

    public bool $templateDirty = false;

    public string $previewLocale = 'en';

    public string $previewText = '';

    public string $previewSubject = '';

    public string $previewBody = '';

    public string $en_subject = '';

    public string $en_body = '';

    public string $ar_subject = '';

    public string $ar_body = '';

    public ?string $brand_from_name = null;

    public string $brand_primary_color = '#0f766e';

    public string $brand_footer_en = '';

    public string $brand_footer_ar = '';

    public ?string $brand_logo_path = null;

    /**
     * @var array<string, true>|null
     */
    protected ?array $customizedTemplateKeyLookup = null;

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
            $this->redirect(SettingsTabRegistry::url('communication::tab'));

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
            $this->templatesEditorFocus = false;
            $this->editLocale = 'en';
            $this->previewLocale = 'en';
            $this->loadTemplateFormState();
            $this->markTemplateClean();
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

        if ($key !== $this->selectedTemplateKey && ! $this->allowTemplateNavigation()) {
            return;
        }

        $this->selectedTemplateKey = $key;
        $this->templatesEditorFocus = true;
        $this->loadTemplateFormState();
        $this->markTemplateClean();
        $this->refreshPreview();
    }

    public function showTemplatesList(): void
    {
        $this->templatesEditorFocus = false;
    }

    public function selectChannelFamily(string $family): void
    {
        if (! in_array($family, NotificationTemplate::channelFamilies(), true)) {
            return;
        }

        if ($family !== $this->selectedChannelFamily && ! $this->allowTemplateNavigation()) {
            return;
        }

        $this->selectedChannelFamily = $family;
        $this->loadTemplateFormState();
        $this->markTemplateClean();
        $this->refreshPreview();
    }

    public function setEditLocale(string $locale): void
    {
        $this->editLocale = $locale === 'ar' ? 'ar' : 'en';
        $this->previewLocale = $this->editLocale;
        $this->refreshPreview();
    }

    public function setTemplateAudienceFilter(string $filter): void
    {
        $this->templateAudienceFilter = match ($filter) {
            'member', 'admin' => $filter,
            default => 'all',
        };
    }

    public function toggleCompareLocales(): void
    {
        $this->compareLocales = ! $this->compareLocales;
    }

    public function togglePreviewExpanded(): void
    {
        $this->previewExpanded = ! $this->previewExpanded;
    }

    public function toggleBrandPanel(): void
    {
        $this->brandPanelOpen = ! $this->brandPanelOpen;
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

        $this->customizedTemplateKeyLookup = null;
        $this->markTemplateClean();

        Notification::make()
            ->title(__('Template saved'))
            ->success()
            ->send();

        $this->refreshPreview();
    }

    public function saveBrandSettings(): void
    {
        CommunicationBrandSettings::saveFromForm([
            'brand_from_name' => $this->brand_from_name,
            'brand_primary_color' => $this->brand_primary_color,
            'brand_footer_en' => $this->brand_footer_en,
            'brand_footer_ar' => $this->brand_footer_ar,
            'brand_logo_path' => $this->brand_logo_path,
        ]);

        Notification::make()
            ->title(__('Email brand saved'))
            ->success()
            ->send();
    }

    public function discardTemplateChanges(): void
    {
        $this->loadTemplateFormState();
        $this->markTemplateClean();
        $this->refreshPreview();

        Notification::make()
            ->title(__('Changes discarded'))
            ->success()
            ->send();
    }

    public function restoreTemplateDefaults(): void
    {
        if ($this->selectedTemplateKey === null) {
            return;
        }

        NotificationTemplateCatalog::restoreDefaults($this->selectedTemplateKey);
        $this->customizedTemplateKeyLookup = null;
        $this->loadTemplateFormState();
        $this->markTemplateClean();
        $this->refreshPreview();

        Notification::make()
            ->title(__('Defaults restored'))
            ->success()
            ->send();
    }

    public function insertVariable(string $variable): void
    {
        $token = '{{'.$variable.'}}';

        if ($this->editLocale === 'ar') {
            $this->ar_body = filled($this->ar_body) ? rtrim($this->ar_body).' '.$token : $token;
        } else {
            $this->en_body = filled($this->en_body) ? rtrim($this->en_body).' '.$token : $token;
        }

        $this->templateDirty = true;
        $this->refreshPreview();
    }

    public function updatedEnSubject(): void
    {
        $this->onTemplateFieldUpdated();
    }

    public function updatedEnBody(): void
    {
        $this->onTemplateFieldUpdated();
    }

    public function updatedArSubject(): void
    {
        $this->onTemplateFieldUpdated();
    }

    public function updatedArBody(): void
    {
        $this->onTemplateFieldUpdated();
    }

    public function refreshPreview(): void
    {
        if ($this->selectedTemplateKey === null) {
            $this->previewText = '';
            $this->previewSubject = '';
            $this->previewBody = '';

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

        $this->previewSubject = (string) ($rendered['subject'] ?? '');
        $this->previewBody = (string) ($rendered['body'] ?? '');
        $this->previewText = trim($this->previewSubject."\n\n".$this->previewBody);
    }

    public function setPreviewLocale(string $locale): void
    {
        $this->previewLocale = $locale === 'ar' ? 'ar' : 'en';
        $this->editLocale = $this->previewLocale;
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

    /**
     * Short labels for segmented channel control.
     *
     * @return array<string, string>
     */
    public function channelFamilyShortOptions(): array
    {
        return [
            NotificationTemplate::FAMILY_EMAIL => __('Email'),
            NotificationTemplate::FAMILY_IN_APP => __('Bell'),
            NotificationTemplate::FAMILY_SMS_PUSH => __('SMS'),
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
            default => __('Wrapped in the branded email layout. Adjust product-wide brand chrome under Email brand on the list.'),
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

    /**
     * Filtered template picker rows for the redesigned UI.
     *
     * @return array<string, array{audience: string, label: string, items: list<array{key: string, label: string, customized: bool}>}>
     */
    public function filteredTemplatePickerGroups(): array
    {
        $grouped = NotificationTemplateCatalog::optionsGroupedByAudience();
        $query = mb_strtolower(trim($this->templateSearch));
        $customized = $this->customizedTemplateKeys();

        $audiences = match ($this->templateAudienceFilter) {
            'member' => ['member' => __('Members')],
            'admin' => ['admin' => __('Admin & automation')],
            default => [
                'member' => __('Members'),
                'admin' => __('Admin & automation'),
            ],
        };

        $result = [];

        foreach ($audiences as $audience => $groupLabel) {
            $items = [];

            foreach ($grouped[$audience] ?? [] as $key => $rawLabel) {
                $label = Lang::formatUiLabel($rawLabel);

                if ($query !== '' && ! str_contains(mb_strtolower($label), $query) && ! str_contains(mb_strtolower($key), $query)) {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'label' => $label,
                    'customized' => isset($customized[$key]),
                ];
            }

            if ($items === []) {
                continue;
            }

            $result[$audience] = [
                'audience' => $audience,
                'label' => $groupLabel,
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function selectedTemplateVariableList(): array
    {
        $definition = $this->selectedTemplateKey
            ? NotificationTemplateCatalog::definition($this->selectedTemplateKey)
            : null;

        return array_values($definition['variables'] ?? []);
    }

    public function selectedTemplateVariables(): string
    {
        return implode(', ', array_map(
            fn (string $v): string => '{{'.$v.'}}',
            $this->selectedTemplateVariableList(),
        ));
    }

    public function selectedTemplateLabel(): string
    {
        if ($this->selectedTemplateKey === null) {
            return '';
        }

        $definition = NotificationTemplateCatalog::definition($this->selectedTemplateKey);

        return Lang::formatUiLabel(__($definition['label'] ?? $this->selectedTemplateKey));
    }

    public function selectedTemplateAudienceLabel(): string
    {
        if ($this->selectedTemplateKey === null) {
            return '';
        }

        return NotificationTemplateCatalog::audienceFor($this->selectedTemplateKey) === 'admin'
            ? __('Admin & automation')
            : __('Members');
    }

    public function communicationsSettingsUrl(): string
    {
        return SettingsTabRegistry::url('communication::tab');
    }

    public function previewCharacterCount(): int
    {
        return mb_strlen(trim($this->previewSubject.' '.$this->previewBody));
    }

    /**
     * @return array<string, true>
     */
    protected function customizedTemplateKeys(): array
    {
        if ($this->customizedTemplateKeyLookup !== null) {
            return $this->customizedTemplateKeyLookup;
        }

        $lookup = [];

        if (! DatabaseSchema::hasTable('notification_templates')) {
            return $this->customizedTemplateKeyLookup = $lookup;
        }

        $rows = NotificationTemplate::query()
            ->get(['key', 'locale', 'channel_family', 'subject', 'body_markdown']);

        foreach ($rows as $row) {
            $defaults = NotificationTemplateCatalog::defaultContent((string) $row->key, (string) $row->locale);

            if ($defaults === null) {
                continue;
            }

            $defaultSubject = (string) ($defaults['subject'] ?? '');
            $defaultBody = (string) ($defaults['body'] ?? '');

            if ((string) $row->subject !== $defaultSubject || (string) $row->body_markdown !== $defaultBody) {
                $lookup[(string) $row->key] = true;
            }
        }

        return $this->customizedTemplateKeyLookup = $lookup;
    }

    protected function markTemplateClean(): void
    {
        $this->templateDirty = false;
    }

    protected function onTemplateFieldUpdated(): void
    {
        $this->templateDirty = true;

        if (! $this->compareLocales) {
            $this->previewLocale = $this->editLocale;
        }

        $this->refreshPreview();
    }

    protected function allowTemplateNavigation(): bool
    {
        if (! $this->templateDirty) {
            return true;
        }

        Notification::make()
            ->title(__('Unsaved template changes'))
            ->body(__('Save or discard your edits before switching template or channel.'))
            ->warning()
            ->send();

        return false;
    }
}
