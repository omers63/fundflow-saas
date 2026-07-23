@php
    $primary = $primaryColor ?? '#0f766e';
    $isRtl = (bool) ($isRtl ?? false);
    $locale = $locale ?? 'en';
    $fundName = $fundName ?? config('app.name');
    $memberName = $memberName ?? '';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $align = $isRtl ? 'right' : 'left';
    $primaryDark = $primaryDark ?? '#115e59';
    $primarySoft = $primarySoft ?? '#f0fdfa';
    $primaryBorder = $primaryBorder ?? '#99f6e4';
    $logoSrc = null;

    if (filled($logoAbsolutePath ?? null) && is_file($logoAbsolutePath)) {
        $logoSrc = $message->embed($logoAbsolutePath);
    }
@endphp
<!DOCTYPE html
    PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ $locale }}" dir="{{ $dir }}">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $fundName }}</title>
</head>

<body
    style="margin:0;padding:0;background-color:#eef2f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;direction:{{ $dir }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background-color:#eef2f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,0.08);">
                    {{-- Header banner --}}
                    <tr>
                        <td
                            style="background:linear-gradient(135deg,{{ $primary }} 0%,{{ $primaryDark }} 100%);background-color:{{ $primary }};padding:28px 28px 22px;text-align:center;">
                            @if ($logoSrc)
                                <img src="{{ $logoSrc }}" alt="" width="72" height="72"
                                    style="display:block;margin:0 auto 14px;border-radius:16px;background:#ffffff;padding:6px;max-height:72px;width:auto;" />
                            @endif
                            <p
                                style="margin:0 0 6px;color:#ccfbf1;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">
                                {{ $headline }}
                            </p>
                            <h1 style="margin:0;color:#ffffff;font-size:24px;line-height:1.3;font-weight:800;">
                                {{ $fundName }}
                            </h1>
                            @if (filled($memberGreeting ?? null))
                                <p style="margin:10px 0 0;color:#ecfeff;font-size:15px;line-height:1.5;">
                                    {{ $memberGreeting }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Accent strip --}}
                    <tr>
                        <td
                            style="background:{{ $primarySoft }};border-bottom:1px solid {{ $primaryBorder }};padding:10px 28px;text-align:center;">
                            <p style="margin:0;color:{{ $primaryDark }};font-size:13px;font-weight:600;">
                                {{ $accentLabel }}
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td dir="{{ $dir }}" lang="{{ $locale }}"
                            style="padding:28px 28px 8px;color:#0f172a;font-size:15px;line-height:1.65;text-align:{{ $align }};direction:{{ $dir }};">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>

                    {{-- Login credentials --}}
                    @if (filled($loginEmail ?? null))
                        <tr>
                            <td style="padding:8px 28px 20px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                    style="width:100%;border-collapse:separate;border:1px solid {{ $primaryBorder }};border-radius:14px;background:{{ $primarySoft }};overflow:hidden;">
                                    <tr>
                                        <td style="padding:18px 20px;text-align:{{ $align }};direction:{{ $dir }};">
                                            <p
                                                style="margin:0 0 12px;color:{{ $primaryDark }};font-size:14px;font-weight:800;letter-spacing:0.02em;text-transform:uppercase;">
                                                {{ $credentialsHeading }}
                                            </p>
                                            <p style="margin:0 0 8px;color:#0f172a;font-size:14px;line-height:1.5;">
                                                <strong style="color:#334155;">{{ $credentialsEmailLabel }}:</strong>
                                                <span
                                                    style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;">{{ $loginEmail }}</span>
                                            </p>
                                            @if (filled($loginPassword ?? null))
                                                <p style="margin:0 0 12px;color:#0f172a;font-size:14px;line-height:1.5;">
                                                    <strong style="color:#334155;">{{ $credentialsPasswordLabel }}:</strong>
                                                    <span
                                                        style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;">{{ $loginPassword }}</span>
                                                </p>
                                            @else
                                                <p style="margin:0 0 12px;color:#475569;font-size:13px;line-height:1.5;">
                                                    {{ $credentialsPasswordHint }}
                                                </p>
                                            @endif
                                            <p
                                                style="margin:0;padding:10px 12px;border-radius:10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;font-weight:700;line-height:1.45;">
                                                {{ $credentialsPasswordUrgent }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- CTA --}}
                    @isset($actionUrl)
                        @if (filled($actionUrl))
                            <tr>
                                <td align="center" style="padding:8px 28px 28px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="border-radius:999px;background:{{ $primary }};">
                                                <a href="{{ $actionUrl }}"
                                                    style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">
                                                    {{ $actionLabel }}
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @endisset

                    {{-- Footer banner --}}
                    <tr>
                        <td style="background:#0f172a;padding:22px 28px;text-align:center;">
                            <p style="margin:0 0 6px;color:#ffffff;font-size:14px;font-weight:700;">
                                {{ $fundName }}
                            </p>
                            <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.5;">
                                {{ $footer }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>