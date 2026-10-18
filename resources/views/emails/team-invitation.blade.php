<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8" />
    <title>{{ __('settings.team.mail_subject', ['team' => $teamName]) }}</title>
</head>
{{-- Plain, inline-styled HTML rather than a markdown-mail layout: this is
     the first Mailable in the product, so there is no existing branded
     layout to extend, and one email with a single call to action does not
     warrant inventing one now. --}}
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:480px;background-color:#ffffff;border-radius:16px;padding:32px;">
                    <tr>
                        <td style="font-size:16px;line-height:1.5;color:#1a1a1a;">
                            <p style="margin:0 0 16px;">
                                {{ __('settings.team.mail_greeting', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
                            </p>
                            {{-- Follow-up from PHASE 3 item 20 (teams/partials/
                            management.blade.php): 'd MMMM Y' is not a valid
                            format string for Carbon's translatedFormat() — it
                            reuses PHP's own date() tokens, where 'M' (short
                            month) is a single-letter token; four in a row are
                            four independent tokens, not one CLDR-style "MMMM"
                            token. Rendered as the month name repeated and
                            mashed together: "18 октябряоктябряоктября 2026".
                            'd F Y' (full month, one token) is what was
                            actually meant — same fix, same reasoning, as the
                            on-screen pending-invitation date this email's own
                            expiry date describes. --}}
                            <p style="margin:0 0 24px;color:#6b7280;font-size:14px;">
                                {{ __('settings.team.mail_expires', ['date' => $expiresAt->translatedFormat('d F Y')]) }}
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:10px;background-color:#2563eb;">
                                        <a href="{{ $acceptUrl }}" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">
                                            {{ __('settings.team.mail_accept_button') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:24px 0 0;color:#9ca3af;font-size:12px;word-break:break-all;">
                                {{ $acceptUrl }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
