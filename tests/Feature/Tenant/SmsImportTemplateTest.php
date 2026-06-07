<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\CreateSmsImportTemplate;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\ListSmsImportTemplates;
use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\User;
use App\Services\SmsImportTemplateSyncService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

/**
 * @return array<string, mixed>
 */
function sampleSmsTemplateRepeaterItem(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sample SMS Template',
        'bank_name' => 'SNB',
        'sms_column' => 'message',
        'is_default' => false,
        'delimiter' => ',',
        'encoding' => 'UTF-8',
        'has_header' => true,
        'skip_rows' => 0,
        'default_transaction_type' => 'credit',
        'duplicate_match_fields' => ['date', 'amount', 'reference'],
        'duplicate_date_tolerance' => 0,
        'credit_keywords' => ['credited'],
        'debit_keywords' => ['debited'],
    ], $overrides);
}

beforeEach(function () {
    $this->initializeTenancy();

    SmsImportTemplate::query()->forceDelete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'SMS Admin',
        'email' => 'sms-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('tenant admin can list sms import templates', function () {
    Filament::setCurrentPanel('tenant');

    SmsImportTemplate::create([
        'name' => 'Al-Rajhi SMS',
        'sms_column' => 'message',
        'is_default' => true,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsImportTemplates::class)
        ->assertSuccessful()
        ->assertSee('Al-Rajhi SMS');
});

test('tenant admin can create sms import template with parsing rules', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(CreateSmsImportTemplate::class)
        ->fillForm([
            'name' => 'Demo SMS Template',
            'bank_name' => 'Al-Rajhi',
            'is_default' => true,
            'sms_column' => 'message',
            'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
            'member_match_pattern' => '/Member[:\s]+(?P<member>M\d+)/',
            'member_match_field' => 'member_number',
            'credit_keywords' => ['credited', 'deposit'],
            'debit_keywords' => ['debited'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = SmsImportTemplate::query()->where('name', 'Demo SMS Template')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_default)->toBeTrue()
        ->and($template->amount_pattern)->toContain('amount')
        ->and($template->toTemplateArray()['member_match_field'])->toBe('member_number');
});

test('only one default sms template per bank name scope', function () {
    $first = SmsImportTemplate::create([
        'name' => 'First',
        'bank_name' => 'SNB',
        'sms_column' => 'text',
        'is_default' => true,
    ]);

    app(SmsImportTemplateSyncService::class)->syncFromSettingsForm([
        sampleSmsTemplateRepeaterItem([
            'id' => $first->id,
            'name' => 'First',
            'is_default' => false,
        ]),
        sampleSmsTemplateRepeaterItem([
            'name' => 'Second',
            'is_default' => true,
        ]),
    ]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(SmsImportTemplate::query()->where('name', 'Second')->value('is_default'))->toBeTrue();
});

test('sms import template resource is hidden from navigation', function () {
    expect(SmsImportTemplateResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SmsImportTemplateResource::getNavigationGroup())->toBe(TenantNavigation::GROUP_ACCOUNTS);
});

test('settings page exposes collapsible sms import templates repeater', function () {
    Filament::setCurrentPanel('tenant');

    SmsImportTemplate::create([
        'name' => 'Existing SMS Template',
        'bank_name' => 'SNB',
        'sms_column' => 'message',
        'is_default' => true,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'sms-templates::tab'])
        ->assertSuccessful()
        ->assertSee(__('SMS import templates'))
        ->assertSee('Existing SMS Template');
});

test('settings sync service creates sms templates from repeater payload', function () {
    app(SmsImportTemplateSyncService::class)->syncFromSettingsForm([
        sampleSmsTemplateRepeaterItem([
            'name' => 'Inline SMS Template',
            'bank_name' => 'Al-Rajhi',
            'is_default' => true,
            'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
            'member_match_pattern' => '/Member[:\s]+(?P<member>M\d+)/',
            'member_match_field' => 'member_number',
        ]),
    ]);

    $template = SmsImportTemplate::query()->where('name', 'Inline SMS Template')->first();

    expect($template)->not->toBeNull()
        ->and($template->bank_name)->toBe('Al-Rajhi')
        ->and($template->amount_pattern)->toContain('amount');
});
