@php
    // Mailable 公開プロパティと衝突するので $headline を直接使わず別名に。
    $title = $headline ?? '新規お問い合わせ';
@endphp
<x-emails.layouts.base :preheader="$title">
    <h1 style="margin: 0 0 24px; font-family: 'Playfair Display', Georgia, serif; font-size: 24px; color: #1c1917;">
        {{ $title }}
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
        @foreach($data as $label => $value)
            <tr>
                <td style="padding: 12px 0; border-bottom: 1px solid #e7e5e4; vertical-align: top;">
                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 4px;">
                        {{ $label }}
                    </div>
                    <div style="color: #292524; word-break: break-word; white-space: pre-wrap;">
                        {{ $value ?? '—' }}
                    </div>
                </td>
            </tr>
        @endforeach
    </table>

    @if(isset($data['メール']))
        <p style="margin: 0 0 16px; font-size: 13px; color: #78716c;">
            そのまま返信するとゲスト ({{ $data['メール'] }}) 宛に送信されます。
        </p>
    @endif

    @if(($adminUrl ?? '') !== '')
        <div style="margin: 24px 0 0; padding-top: 24px; border-top: 1px solid #e7e5e4;">
            <a href="{{ $adminUrl }}" style="display: inline-block; padding: 10px 24px; background-color: #1c1917; color: #ffffff; text-decoration: none; font-size: 12px; letter-spacing: 0.15em; text-transform: uppercase;">
                管理画面でチャットを開く
            </a>
        </div>
    @endif
</x-emails.layouts.base>
