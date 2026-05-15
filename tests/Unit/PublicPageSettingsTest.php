<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\PublicPageSettings;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Setting::query()->where('group', PublicPageSettings::GROUP)->delete();
    Member::query()->delete();
});

it('reports enrollment open when no limit is configured', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'membership_no_limit' => true,
    ]);

    expect(PublicPageSettings::enrollmentIsOpen())->toBeTrue()
        ->and(PublicPageSettings::remainingEnrollmentSlots())->toBeNull();
});

it('reports enrollment closed when active members reach the cap', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'membership_no_limit' => false,
        'membership_max_members' => '2',
    ]);

    Member::factory()->count(2)->create(['status' => 'active']);

    expect(PublicPageSettings::enrollmentIsOpen())->toBeFalse()
        ->and(PublicPageSettings::remainingEnrollmentSlots())->toBe(0);
});

it('returns the fee for each application type', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
        'fee_resume' => '50',
        'fee_renew' => '25',
    ]);

    expect(PublicPageSettings::feeForType('new'))->toBe(100.0)
        ->and(PublicPageSettings::feeForType('resume'))->toBe(50.0)
        ->and(PublicPageSettings::feeForType('renew'))->toBe(25.0);
});

it('stores public contact details for the footer', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'contact_email' => 'fund@example.com',
        'contact_phone' => '+966 50 000 0000',
    ]);

    expect(PublicPageSettings::contactEmail())->toBe('fund@example.com')
        ->and(PublicPageSettings::contactPhone())->toBe('+966 50 000 0000')
        ->and(PublicPageSettings::hasContactDetails())->toBeTrue();
});

it('stores and normalizes fee transfer bank details', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_transfer_bank_name' => 'Al Rajhi Bank',
        'fee_transfer_iban' => 'sa12 3456 7890 1234 5678 9012',
    ]);

    expect(PublicPageSettings::feeTransferBankName())->toBe('Al Rajhi Bank')
        ->and(PublicPageSettings::feeTransferIban())->toBe('SA1234567890123456789012')
        ->and(PublicPageSettings::hasFeeTransferDetails())->toBeTrue();
});

it('uses the built-in terms download route when no custom rules url is configured', function () {
    expect(PublicPageSettings::termsAndConditionsDownloadUrl())
        ->toContain('/downloads/terms-and-conditions')
        ->and(PublicPageSettings::hasTermsAndConditionsDownload())->toBeTrue();
});

it('uses the default FundFlow brand logo when no custom logo is configured', function () {
    expect(PublicPageSettings::hasFundLogo())->toBeFalse()
        ->and(PublicPageSettings::fundLogoUrl())->toContain('favicon-192x192.png')
        ->and(PublicPageSettings::fundPanelBrandLogoUrl())->toContain('favicon-192x192.png');
});

it('serves uploaded fund logos through the tenancy assets route when tenancy is active', function () {
    $this->initializeTenancy();
    Storage::fake('public');

    Storage::disk('public')->put('fund-branding/logo.png', 'logo');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/logo.png',
    ]);

    expect(PublicPageSettings::fundLogoUrl())->toContain('/tenancy/assets/fund-branding/logo.png');
});

it('falls back to the default brand logo when the configured file is missing', function () {
    $this->initializeTenancy();

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/missing.png',
    ]);

    expect(PublicPageSettings::fundLogoUrl())->toContain('favicon-192x192.png');
});

it('stores fund logo path and exposes a public url', function () {
    Storage::fake('public');
    Storage::disk('public')->put('fund-branding/logo.png', 'logo-bytes');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/logo.png',
    ]);

    expect(PublicPageSettings::hasFundLogo())->toBeTrue()
        ->and(PublicPageSettings::fundLogoPath())->toBe('fund-branding/logo.png')
        ->and(PublicPageSettings::fundLogoUrl())->toContain('fund-branding/logo.png');
});

it('deletes the previous logo file when replaced', function () {
    Storage::fake('public');
    Storage::disk('public')->put('fund-branding/old.png', 'old');
    Storage::disk('public')->put('fund-branding/new.png', 'new');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/old.png',
    ]);

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/new.png',
    ]);

    Storage::disk('public')->assertMissing('fund-branding/old.png');
    Storage::disk('public')->assertExists('fund-branding/new.png');
});
