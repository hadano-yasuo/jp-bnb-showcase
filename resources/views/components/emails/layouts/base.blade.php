@props([
    'preheader' => null,
])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pages.brand') }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #faf6ee; font-family: 'Noto Sans JP', 'Hiragino Sans', 'Meiryo', sans-serif; color: #292524;">
    @if($preheader)
        {{-- Preheader (受信箱プレビューに表示、body には見せない) --}}
        <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
            {{ $preheader }}
        </div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #faf6ee;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; width: 100%; background-color: #ffffff;">
                    {{-- Header --}}
                    <tr>
                        <td style="padding: 32px 40px 24px; border-bottom: 1px solid #e7e5e4;">
                            <div style="font-family: 'Playfair Display', Georgia, serif; font-size: 20px; letter-spacing: 0.05em; color: #1c1917;">
                                {{ __('pages.brand') }}
                            </div>
                        </td>
                    </tr>
                    {{-- Body --}}
                    <tr>
                        <td style="padding: 32px 40px 40px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    {{-- Footer --}}
                    <tr>
                        <td style="padding: 24px 40px; background-color: #f5f5f4; border-top: 1px solid #e7e5e4; text-align: center; font-size: 12px; color: #78716c;">
                            <p style="margin: 0 0 8px;">{{ __('pages.footer.copyright') }}</p>
                            <p style="margin: 0;">
                                <a href="{{ config('public_hp.domain') ? 'https://'.config('public_hp.domain') : 'https://example.com' }}" style="color: #78716c; text-decoration: none;">
                                    {{ config('public_hp.domain') ?: 'example.com' }}
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
