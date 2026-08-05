<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\CommunicationsWorkspacePage;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Support\CommunicationBrandSettings;
use App\Support\NotificationTemplateCatalog;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    NotificationTemplateCatalog::seedMissingDefaults();
});

function createCommunicationsTemplatesAdmin(): User
{
    return User::create([
        'name' => 'Templates Admin',
        'email' => 'templates-admin-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

test('templates workspace starts on list focus and opens editor on select', function () {
    $admin = createCommunicationsTemplatesAdmin();

    Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->assertSuccessful()
        ->assertSet('templatesEditorFocus', false)
        ->assertSet('selectedTemplateKey', 'contribution_due')
        ->assertSee(__('Search templates…'), false)
        ->assertSee(__('Email brand'), false)
        ->call('selectTemplate', 'contribution_due')
        ->assertSet('templatesEditorFocus', true)
        ->assertSeeHtml('ff-templates-workspace--editor-focus')
        ->assertSee(__('Save template'), false)
        ->assertSee(__('Channel'), false)
        ->assertSee(__('Placeholders'), false)
        ->call('showTemplatesList')
        ->assertSet('templatesEditorFocus', false);
});

test('templates workspace uses single language editor and appends variable chips', function () {
    $admin = createCommunicationsTemplatesAdmin();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->call('selectTemplate', 'contribution_due')
        ->assertSet('editLocale', 'en')
        ->assertSet('compareLocales', false)
        ->call('setEditLocale', 'ar')
        ->assertSet('editLocale', 'ar')
        ->assertSet('previewLocale', 'ar')
        ->call('insertVariable', 'amount')
        ->assertSet('templateDirty', true);

    expect($component->get('ar_body'))->toContain('{{amount}}');
});

test('templates workspace blocks channel switch while dirty until discard', function () {
    $admin = createCommunicationsTemplatesAdmin();

    Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->call('selectTemplate', 'contribution_due')
        ->set('en_subject', 'Custom due subject')
        ->assertSet('templateDirty', true)
        ->call('selectChannelFamily', NotificationTemplate::FAMILY_IN_APP)
        ->assertSet('selectedChannelFamily', NotificationTemplate::FAMILY_EMAIL)
        ->call('discardTemplateChanges')
        ->assertSet('templateDirty', false)
        ->call('selectChannelFamily', NotificationTemplate::FAMILY_IN_APP)
        ->assertSet('selectedChannelFamily', NotificationTemplate::FAMILY_IN_APP);
});

test('templates workspace saves template without brand and brand save is independent', function () {
    $admin = createCommunicationsTemplatesAdmin();

    Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->call('selectTemplate', 'contribution_due')
        ->set('en_subject', 'Due now')
        ->set('en_body', 'Please pay {{amount}}')
        ->set('brand_from_name', 'Should not save from template')
        ->call('saveTemplate')
        ->assertNotified(__('Template saved'));

    $row = NotificationTemplate::query()
        ->where('key', 'contribution_due')
        ->where('locale', 'en')
        ->where('channel_family', NotificationTemplate::FAMILY_EMAIL)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('Due now')
        ->and(CommunicationBrandSettings::fromName())->not->toBe('Should not save from template');

    Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->set('brand_from_name', 'Fund Flow Brand')
        ->call('saveBrandSettings')
        ->assertNotified(__('Email brand saved'));

    expect(CommunicationBrandSettings::fromName())->toBe('Fund Flow Brand');
});

test('templates workspace channel-shaped preview and audience filter work', function () {
    $admin = createCommunicationsTemplatesAdmin();

    Livewire::actingAs($admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'templates'])
        ->call('selectTemplate', 'contribution_due')
        ->call('selectChannelFamily', NotificationTemplate::FAMILY_SMS_PUSH)
        ->assertSeeHtml('ff-templates-preview__sms')
        ->call('selectChannelFamily', NotificationTemplate::FAMILY_IN_APP)
        ->assertSeeHtml('ff-templates-preview__bell')
        ->call('selectChannelFamily', NotificationTemplate::FAMILY_EMAIL)
        ->assertSeeHtml('ff-templates-preview__email')
        ->call('setTemplateAudienceFilter', 'admin')
        ->assertSet('templateAudienceFilter', 'admin')
        ->set('templateSearch', 'zzzz-no-match')
        ->assertSee(__('No templates match your search.'), false);
});
