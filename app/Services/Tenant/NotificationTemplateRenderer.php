<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\NotificationTemplate;
use App\Support\CommunicationBrandSettings;
use App\Support\NotificationTemplateCatalog;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
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
    public function brandedMailMessage(string $key, string $locale, array $variables = []): MailMessage
    {
        $rendered = $this->render($key, NotificationTemplate::FAMILY_EMAIL, $locale, $variables);
        $footer = CommunicationBrandSettings::footerForLocale($locale);
        $color = CommunicationBrandSettings::primaryColor();
        $fromName = CommunicationBrandSettings::fromName();

        $logoPath = CommunicationBrandSettings::logoPath();
        $logoAbsolute = $logoPath !== null ? storage_path('app/public/'.$logoPath) : null;

        $message = (new MailMessage)
            ->subject($rendered['subject'])
            ->markdown('mail.branded-notification', [
                'bodyHtml' => new HtmlString($rendered['body_html']),
                'footer' => $footer,
                'primaryColor' => $color,
                'actionUrl' => isset($variables['action_url']) ? (string) $variables['action_url'] : null,
                'actionLabel' => isset($variables['action_label']) ? (string) $variables['action_label'] : __('Open'),
                'logoPath' => ($logoAbsolute !== null && is_file($logoAbsolute)) ? $logoPath : null,
            ]);

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
}
