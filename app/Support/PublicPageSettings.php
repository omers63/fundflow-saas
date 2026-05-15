<?php

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Illuminate\Support\Facades\Storage;

final class PublicPageSettings
{
    public const GROUP = 'public';

    public static function fundName(?string $default = null): string
    {
        return (string) (self::get('fund_name') ?: $default ?: Setting::get('general', 'fund_name', 'Family Fund'));
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
            'fund_name' => 'Family Fund',
            'fund_logo' => '',
            'membership_no_limit' => '1',
            'membership_max_members' => '',
            'fee_new' => '0',
            'fee_resume' => '0',
            'fee_renew' => '0',
            'rules_and_conditions_url' => '',
            'membership_application_document_url' => '',
            'fee_transfer_bank_name' => '',
            'fee_transfer_iban' => '',
            'contact_email' => '',
            'contact_phone' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
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

            Setting::set(self::GROUP, $key, is_scalar($value) ? (string) $value : '');
        }

        if (filled($values['fund_name'] ?? null)) {
            Setting::set('general', 'fund_name', (string) $values['fund_name']);
        }
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
