<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isResend ? 'Your password link' : 'Set up your account' }}</title>
</head>
{{-- Table layout and inline styles throughout: mail clients strip <style>
     blocks and flex/grid, so this is the one place the project's utility
     classes cannot be used. --}}
<body style="margin:0; padding:0; background-color:#FAF8F5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#FAF8F5; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FFFFFF; border:1px solid #EAE4DE; border-radius:8px;">

                    <tr>
                        <td style="padding:28px 32px 20px; border-bottom:1px solid #EAE4DE;" align="center">
                            <span style="font-family:Georgia,'Times New Roman',serif; font-size:24px; letter-spacing:2px; color:#1F1D1D; text-transform:uppercase;">
                                {{ $brand }}
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 32px 8px; font-family:Helvetica,Arial,sans-serif;">
                            <h1 style="margin:0 0 16px; font-size:19px; font-weight:600; color:#1F1D1D;">
                                {{ $isResend ? 'Your password link' : 'Welcome, '.$name }}
                            </h1>

                            <p style="margin:0 0 14px; font-size:14px; line-height:22px; color:#33302F;">
                                @if($isResend)
                                    Here is a fresh link to set your password for your
                                    {{ $brand }} account.
                                @else
                                    You have been given {{ $roleLabel }} access to the
                                    {{ $brand }} admin panel. Choose your password using the
                                    button below, then sign in with your email address.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 32px 4px;" align="center">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color:#CB6B88; border-radius:2px;">
                                        <a href="{{ $setupUrl }}"
                                           style="display:inline-block; padding:14px 34px; font-family:Helvetica,Arial,sans-serif; font-size:12px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:#FFFFFF; text-decoration:none;">
                                            Set your password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px 28px; font-family:Helvetica,Arial,sans-serif;">
                            <p style="margin:0 0 14px; font-size:13px; line-height:20px; color:#666666;">
                                This link is valid for {{ $expiryHours }} hours. If it expires,
                                ask your {{ $brand }} contact to send a new one.
                            </p>

                            <p style="margin:0 0 6px; font-size:12px; line-height:18px; color:#666666;">
                                If the button does not work, copy this address into your browser:
                            </p>
                            <p style="margin:0 0 18px; font-size:12px; line-height:18px; word-break:break-all;">
                                <a href="{{ $setupUrl }}" style="color:#AD3D5F;">{{ $setupUrl }}</a>
                            </p>

                            <p style="margin:0; font-size:12px; line-height:18px; color:#666666; border-top:1px solid #EAE4DE; padding-top:16px;">
                                Didn't expect this email? You can ignore it — no account is
                                active until a password is set.
                            </p>
                        </td>
                    </tr>

                </table>

                <p style="margin:18px 0 0; font-family:Helvetica,Arial,sans-serif; font-size:11px; color:#8C807B;">
                    &copy; {{ date('Y') }} {{ $brand }}. All rights reserved.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
