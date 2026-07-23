<?php

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Illuminate\Support\Facades\Storage;

final class PublicPageSettings
{
    public const GROUP = 'public';

    /** Matches Filament panel `brandLogoHeight()` on tenant and member panels. */
    public const BRAND_LOGO_HEIGHT = '5rem';

    public static function fundName(?string $default = null, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $primaryKey = $locale === 'ar' ? 'fund_name_ar' : 'fund_name_en';
        $fallbackKey = $locale === 'ar' ? 'fund_name_en' : 'fund_name_ar';

        $name = trim((string) self::get($primaryKey));

        if ($name === '') {
            $name = trim((string) self::get($fallbackKey));
        }

        if ($name === '') {
            $name = trim((string) self::get('fund_name'));
        }

        if ($name !== '') {
            return $name;
        }

        if (filled($default)) {
            return $default;
        }

        $generalFallback = (string) Setting::get('general', 'fund_name', '');

        if ($generalFallback !== '') {
            return $generalFallback;
        }

        return $locale === 'ar' ? 'صندوق العائلة' : 'Family Fund';
    }

    public static function fundLogoPath(): ?string
    {
        return self::nullableString('fund_logo');
    }

    public static function fundLogoUrl(): string
    {
        return self::resolveFundLogoUrl(FundflowBrand::logoUrl());
    }

    /** Brand mark for Filament panels (topbar / sidebar). */
    public static function fundPanelBrandLogoUrl(): string
    {
        return self::resolveFundLogoUrl(FundflowBrand::panelLogoUrl());
    }

    protected static function resolveFundLogoUrl(string $defaultUrl): string
    {
        $path = self::fundLogoPath();

        if ($path === null) {
            return $defaultUrl;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (TenantAssetUrl::publicDiskExists($path)) {
            return TenantAssetUrl::publicDisk($path);
        }

        return $defaultUrl;
    }

    public static function hasFundLogo(): bool
    {
        return self::fundLogoPath() !== null;
    }

    public static function membershipNoLimit(): bool
    {
        return filter_var(self::get('membership_no_limit', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function membershipMaxMembers(): ?int
    {
        $value = self::get('membership_max_members');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    public static function feeNew(): float
    {
        return (float) self::get('fee_new', 0);
    }

    public static function feeResume(): float
    {
        return (float) self::get('fee_resume', 0);
    }

    public static function feeRenew(): float
    {
        return (float) self::get('fee_renew', 0);
    }

    public static function feeForType(string $type): float
    {
        return match ($type) {
            'resume' => self::feeResume(),
            'renew' => self::feeRenew(),
            default => self::feeNew(),
        };
    }

    public static function rulesAndConditionsUrl(): ?string
    {
        return self::nullableUrl('rules_and_conditions_url');
    }

    public static function termsAndConditionsDownloadUrl(): ?string
    {
        $custom = self::rulesAndConditionsUrl();

        if ($custom !== null) {
            return $custom;
        }

        if (is_file(public_path('downloads/fund-terms-and-conditions.pdf'))) {
            return route('tenant.downloads.terms-and-conditions');
        }

        return null;
    }

    public static function hasTermsAndConditionsDownload(): bool
    {
        return self::termsAndConditionsDownloadUrl() !== null;
    }

    public static function membershipApplicationDocumentUrl(): ?string
    {
        return self::nullableUrl('membership_application_document_url');
    }

    /**
     * URL for the blank membership application template (fund settings or default rules PDF).
     */
    public static function membershipApplicationFormUploadDownloadUrl(): ?string
    {
        return self::membershipApplicationDocumentUrl()
            ?? self::termsAndConditionsDownloadUrl();
    }

    public static function feeTransferBankName(): ?string
    {
        return self::nullableString('fee_transfer_bank_name');
    }

    public static function feeTransferIban(): ?string
    {
        $iban = self::nullableString('fee_transfer_iban');

        return $iban !== null ? strtoupper(str_replace(' ', '', $iban)) : null;
    }

    public static function hasFeeTransferDetails(): bool
    {
        return self::feeTransferBankName() !== null && self::feeTransferIban() !== null;
    }

    public static function contactEmail(): ?string
    {
        return self::nullableString('contact_email');
    }

    public static function contactPhone(): ?string
    {
        return self::nullableString('contact_phone');
    }

    public static function hasContactDetails(): bool
    {
        return self::contactEmail() !== null || self::contactPhone() !== null;
    }

    public static function activeMemberCount(): int
    {
        return Member::query()->active()->count();
    }

    public static function enrollmentIsOpen(): bool
    {
        if (self::membershipNoLimit()) {
            return true;
        }

        $max = self::membershipMaxMembers();

        if ($max === null) {
            return true;
        }

        return self::activeMemberCount() < $max;
    }

    public static function remainingEnrollmentSlots(): ?int
    {
        if (self::membershipNoLimit()) {
            return null;
        }

        $max = self::membershipMaxMembers();

        if ($max === null) {
            return null;
        }

        return max(0, $max - self::activeMemberCount());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'fund_name_en' => 'Samman Family Fund',
            'fund_name_ar' => 'صندوق عائلة آل سمان',
            'fund_name' => 'Samman Family Fund',
            'fund_logo' => '',
            'membership_no_limit' => '0',
            'membership_max_members' => '100',
            'fee_new' => '150',
            'fee_resume' => '0',
            'fee_renew' => '0',
            'rules_and_conditions_url' => '',
            'membership_application_document_url' => '',
            'fee_transfer_bank_name' => 'Al Rajhi Bank',
            'fee_transfer_iban' => 'SA761234560000123101',
            'contact_email' => 'admin@fundflow.sa',
            'contact_phone' => '+966 557744668',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Setting::getGroup(self::GROUP);

        return self::normalizeFundNameKeys(array_merge(self::defaults(), $stored), $stored);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null || $value === '') {
            return self::defaults()[$key] ?? $default;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (in_array($key, ['membership_no_limit'], true)) {
                Setting::set(self::GROUP, $key, $value ? '1' : '0');

                continue;
            }

            if ($key === 'membership_max_members') {
                Setting::set(self::GROUP, $key, filled($value) ? (string) (int) $value : '');

                continue;
            }

            if ($key === 'fee_transfer_iban') {
                $normalized = filled($value)
                    ? strtoupper(str_replace(' ', '', (string) $value))
                    : '';
                Setting::set(self::GROUP, $key, $normalized);

                continue;
            }

            if ($key === 'fund_logo') {
                self::persistFundLogo(self::normalizeUploadPath($value));

                continue;
            }

            if ($key === 'fund_name') {
                continue;
            }

            Setting::set(self::GROUP, $key, is_scalar($value) ? (string) $value : '');
        }

        $fundNameEn = trim((string) ($values['fund_name_en'] ?? ''));

        if ($fundNameEn !== '') {
            Setting::set('general', 'fund_name', $fundNameEn);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function normalizeFundNameKeys(array $values, array $stored): array
    {
        $legacy = trim((string) ($stored['fund_name'] ?? ''));

        if (! array_key_exists('fund_name_en', $stored) && $legacy !== '') {
            $values['fund_name_en'] = $legacy;
        }

        if (! array_key_exists('fund_name_ar', $stored) && $legacy !== '' && trim((string) ($values['fund_name_ar'] ?? '')) === '') {
            $values['fund_name_ar'] = $legacy;
        }

        unset($values['fund_name']);

        return $values;
    }

    private static function nullableUrl(string $key): ?string
    {
        $url = trim((string) self::get($key, ''));

        return $url !== '' ? $url : null;
    }

    private static function nullableString(string $key): ?string
    {
        $value = trim((string) self::get($key, ''));

        return $value !== '' ? $value : null;
    }

    private static function normalizeUploadPath(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[array_key_first($value)] ?? '';
        }

        return is_string($value) ? trim($value) : '';
    }

    private static function persistFundLogo(string $newPath): void
    {
        $previous = self::fundLogoPath();

        if ($previous !== null && $previous !== $newPath && ! str_starts_with($previous, 'http')) {
            Storage::disk('public')->delete($previous);
        }

        Setting::set(self::GROUP, 'fund_logo', $newPath);
    }
}
