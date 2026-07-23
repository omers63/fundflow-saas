<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\NotificationTemplate;
use App\Support\CommunicationBrandSettings;
use App\Support\FundflowBrand;
use App\Support\NotificationTemplateCatalog;
use App\Support\PublicPageSettings;
use App\Support\TenantAssetUrl;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class NotificationTemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: string, body: string, body_html: string}
     */
    public function render(string $key, string $channelFamily, string $locale, array $variables = []): array
    {
        $row = NotificationTemplate::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('channel_family', $channelFamily)
            ->first();

        $defaults = NotificationTemplateCatalog::defaultContent($key, $locale)
            ?? ['subject' => $key, 'body' => ''];

        $subjectTemplate = $row?->subject ?? $defaults['subject'];
        $bodyTemplate = $row?->body_markdown ?? $defaults['body'];

        $subject = $this->interpolate((string) $subjectTemplate, $variables);
        $body = $this->interpolate($bodyTemplate, $variables);
        $bodyHtml = (string) Markdown::parse($body);

        return [
            'subject' => $subject,
            'body' => $body,
            'body_html' => $bodyHtml,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function brandedMailMessage(
        string $key,
        string $locale,
        array $variables = [],
        string $theme = 'default',
    ): MailMessage {
        $footer = CommunicationBrandSettings::footerForLocale($locale);
        $color = CommunicationBrandSettings::primaryColor();
        $fromName = CommunicationBrandSettings::fromName();
        $isRtl = $locale === 'ar';
        $logoAbsolute = $this->resolveLogoAbsolutePath();

        // Keep temporary passwords out of markdown interpolation (custom templates / logs).
        $loginPassword = isset($variables['login_password']) && filled($variables['login_password'])
            ? (string) $variables['login_password']
            : null;
        $loginEmail = isset($variables['login_email']) && filled($variables['login_email'])
            ? (string) $variables['login_email']
            : null;
        $markdownVariables = $variables;
        unset($markdownVariables['login_password']);

        $rendered = $this->render($key, NotificationTemplate::FAMILY_EMAIL, $locale, $markdownVariables);

        if ($theme === 'onboarding') {
            $bodyHtml = $this->styleOnboardingBodyHtml($rendered['body_html'], $color, $isRtl);
            $fundName = (string) ($variables['fund_name'] ?? PublicPageSettings::fundName(locale: $locale));
            $memberName = (string) ($variables['member_name'] ?? '');

            $message = (new MailMessage)
                ->subject($rendered['subject'])
                ->view('mail.member-onboarding-greeting', [
                    'bodyHtml' => new HtmlString($bodyHtml),
                    'footer' => $footer,
                    'primaryColor' => $color,
                    'primaryDark' => $this->shadeColor($color, -0.18),
                    'primarySoft' => $this->mixWithWhite($color, 0.92),
                    'primaryBorder' => $this->mixWithWhite($color, 0.55),
                    'actionUrl' => isset($variables['action_url']) ? (string) $variables['action_url'] : null,
                    'actionLabel' => isset($variables['action_label'])
                        ? (string) $variables['action_label']
                        : __('Open member portal', [], $locale),
                    'logoAbsolutePath' => $logoAbsolute,
                    'locale' => $locale,
                    'isRtl' => $isRtl,
                    'fundName' => $fundName,
                    'memberName' => $memberName,
                    'headline' => __('Welcome aboard', [], $locale),
                    'memberGreeting' => filled($memberName)
                        ? __('Hello :name — your member portal is ready.', ['name' => $memberName], $locale)
                        : null,
                    'accentLabel' => __('Accounts · money flow · portal & app guide', [], $locale),
                    'loginEmail' => $loginEmail,
                    'loginPassword' => $loginPassword,
                    'credentialsHeading' => __('Your login credentials', [], $locale),
                    'credentialsEmailLabel' => __('Email', [], $locale),
                    'credentialsPasswordLabel' => __('Temporary password', [], $locale),
                    'credentialsPasswordHint' => __('Use the password you set during registration. Contact your fund administrator if you need a reset.', [], $locale),
                    'credentialsPasswordUrgent' => __('Change this password as soon as you sign in.', [], $locale),
                ]);
        } else {
            $logoPath = CommunicationBrandSettings::logoPath();
            $logoAbsoluteForDefault = $logoPath !== null ? storage_path('app/public/'.$logoPath) : null;

            $message = (new MailMessage)
                ->subject($rendered['subject'])
                ->markdown('mail.branded-notification', [
                    'bodyHtml' => new HtmlString($rendered['body_html']),
                    'footer' => $footer,
                    'primaryColor' => $color,
                    'actionUrl' => isset($variables['action_url']) ? (string) $variables['action_url'] : null,
                    'actionLabel' => isset($variables['action_label']) ? (string) $variables['action_label'] : __('Open'),
                    'logoPath' => ($logoAbsoluteForDefault !== null && is_file($logoAbsoluteForDefault)) ? $logoPath : null,
                    'locale' => $locale,
                    'isRtl' => $isRtl,
                ]);
        }

        if ($fromName !== null && filled(config('mail.from.address'))) {
            $message->from((string) config('mail.from.address'), $fromName);
        }

        return $message;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function plainText(string $key, string $channelFamily, string $locale, array $variables = []): string
    {
        $rendered = $this->render($key, $channelFamily, $locale, $variables);
        $body = trim(Str::of($rendered['body'])->replace(['**', '__', '*', '_', '`'], '')->__toString());
        $subject = trim($rendered['subject']);

        if ($subject === '' || $subject === $body) {
            return $body;
        }

        return trim($subject.($body !== '' ? ': '.$body : ''));
    }

    public function resolveLogoAbsolutePath(): ?string
    {
        $brandPath = CommunicationBrandSettings::logoPath();

        if ($brandPath !== null) {
            $absolute = storage_path('app/public/'.$brandPath);

            if (is_file($absolute)) {
                return $absolute;
            }
        }

        $fundPath = PublicPageSettings::fundLogoPath();

        if ($fundPath !== null && ! str_starts_with($fundPath, 'http://') && ! str_starts_with($fundPath, 'https://')) {
            if (TenantAssetUrl::publicDiskExists($fundPath)) {
                $absolute = Storage::disk('public')->path($fundPath);

                if (is_file($absolute)) {
                    return $absolute;
                }
            }
        }

        $fallback = public_path(FundflowBrand::LOGO_ASSET);

        return is_file($fallback) ? $fallback : null;
    }

    public function styleOnboardingBodyHtml(string $html, string $primary, bool $isRtl): string
    {
        $align = $isRtl ? 'right' : 'left';
        $borderSide = $isRtl ? 'border-right' : 'border-left';
        $soft = $this->mixWithWhite($primary, 0.92);
        $border = $this->mixWithWhite($primary, 0.55);
        $dark = $this->shadeColor($primary, -0.18);

        $html = preg_replace(
            '/<h2(\s[^>]*)?>/i',
            '<h2$1 style="margin:28px 0 12px;padding:10px 14px;background:'.$soft.';'.$borderSide.':4px solid '.$primary.';border-radius:8px;color:'.$dark.';font-size:17px;line-height:1.35;font-weight:800;text-align:'.$align.';">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<h3(\s[^>]*)?>/i',
            '<h3$1 style="margin:18px 0 8px;color:'.$dark.';font-size:15px;line-height:1.4;font-weight:700;text-align:'.$align.';">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<p(\s[^>]*)?>/i',
            '<p$1 style="margin:0 0 12px;color:#334155;font-size:15px;line-height:1.65;text-align:'.$align.';">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<ul(\s[^>]*)?>/i',
            '<ul$1 style="margin:0 0 16px;padding-'.($isRtl ? 'right' : 'left').':20px;color:#334155;">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<ol(\s[^>]*)?>/i',
            '<ol$1 style="margin:0 0 16px;padding-'.($isRtl ? 'right' : 'left').':20px;color:#334155;">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<li(\s[^>]*)?>/i',
            '<li$1 style="margin:0 0 8px;line-height:1.55;">',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/<strong(\s[^>]*)?>/i',
            '<strong$1 style="color:'.$dark.';font-weight:700;">',
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function interpolate(string $template, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $name => $value) {
            $replacements['{{'.$name.'}}'] = (string) ($value ?? '');
            $replacements['{'.$name.'}'] = (string) ($value ?? '');
        }

        return strtr($template, $replacements);
    }

    private function shadeColor(string $hex, float $percent): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $r = $this->clampChannel((int) round($r + (255 * $percent)));
        $g = $this->clampChannel((int) round($g + (255 * $percent)));
        $b = $this->clampChannel((int) round($b + (255 * $percent)));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function mixWithWhite(string $hex, float $whiteRatio): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $whiteRatio = max(0.0, min(1.0, $whiteRatio));
        $r = (int) round(($r * (1 - $whiteRatio)) + (255 * $whiteRatio));
        $g = (int) round(($g * (1 - $whiteRatio)) + (255 * $whiteRatio));
        $b = (int) round(($b * (1 - $whiteRatio)) + (255 * $whiteRatio));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [15, 118, 110];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function clampChannel(int $value): int
    {
        return max(0, min(255, $value));
    }
}
