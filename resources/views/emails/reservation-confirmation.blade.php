@php
    $copy = match ($locale) {
        'en' => [
            'preheader' => 'Your reservation is confirmed.',
            'heading' => 'Reservation confirmed',
            'greeting' => 'Dear ' . trim(($booking->last_name ?? '') . ' ' . ($booking->first_name ?? '')) ?: 'Guest',
            'intro' => 'Thank you for booking with Sample Stay. Your reservation has been confirmed.',
            'labels' => [
                'confirmation' => 'CONFIRMATION',
                'checkin' => 'CHECK-IN',
                'checkout' => 'CHECK-OUT',
                'guests' => 'GUESTS',
            ],
            'guide' => 'We will send check-in details and access instructions a few days before your arrival. If your booking is for today or tomorrow, we will contact you as soon as possible.',
            'chat_label' => 'Message us',
            'chat_hint' => 'Open this chat to view your inquiry history and reply to your host.',
            'registration_label' => 'Guest information',
            'registration_hint' => 'Please register all guests before check-in.',
            'signoff' => 'Sample Stay Team',
        ],
        'ja' => [
            'preheader' => 'ご予約完了のお知らせ',
            'heading' => 'ご予約が確定いたしました',
            'greeting' => trim(($booking->last_name ?? '') . ' ' . ($booking->first_name ?? '')) . ' 様',
            'intro' => 'この度は Sample Stay をご予約いただきありがとうございます。ご予約が確定いたしましたのでお知らせいたします。',
            'labels' => [
                'confirmation' => '予約番号',
                'checkin' => 'チェックイン',
                'checkout' => 'チェックアウト',
                'guests' => '宿泊人数',
            ],
            'guide' => 'チェックイン方法・アクセス詳細はご到着数日前にご案内いたします。当日または前日のご予約の場合は、可能な限りすぐにご連絡いたします。',
            'chat_label' => 'ホストに連絡する',
            'chat_hint' => '問い合わせ時のチャットをそのままご利用いただけます。ご不明点がございましたらこちらからご連絡ください。',
            'registration_label' => 'ゲスト情報の登録',
            'registration_hint' => 'チェックイン前にご宿泊者全員の情報登録をお願いいたします。',
            'signoff' => 'Sample Stay スタッフ一同',
        ],
        default => [
            'preheader' => 'Your reservation is confirmed.',
            'heading' => 'Reservation confirmed',
            'greeting' => 'Guest',
            'intro' => 'Thank you for booking with Sample Stay.',
            'labels' => [
                'confirmation' => 'CONFIRMATION',
                'checkin' => 'CHECK-IN',
                'checkout' => 'CHECK-OUT',
                'guests' => 'GUESTS',
            ],
            'guide' => 'We will send check-in details a few days before arrival. If your booking is for today or tomorrow, we will contact you as soon as possible.',
            'chat_label' => 'Message us',
            'chat_hint' => 'Open this chat to view your inquiry history and reply to your host.',
            'registration_label' => 'Guest information',
            'registration_hint' => 'Please register all guests before check-in.',
            'signoff' => 'Sample Stay Team',
        ],
    };
@endphp

<x-emails.layouts.base :preheader="$copy['preheader']">
    <h1 style="margin: 0 0 24px; font-family: 'Playfair Display', Georgia, serif; font-size: 24px; color: #1c1917;">
        {{ $copy['heading'] }}
    </h1>

    <p style="margin: 0 0 16px; line-height: 1.7;">
        {{ $copy['greeting'] }}
    </p>

    <p style="margin: 0 0 24px; line-height: 1.7;">
        {{ $copy['intro'] }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
        <tr>
            <td style="padding: 12px 0; border-bottom: 1px solid #e7e5e4;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 4px;">{{ $copy['labels']['confirmation'] }}</div>
                <div style="color: #292524;">{{ $booking->beds24_booking_id }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 0; border-bottom: 1px solid #e7e5e4;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 4px;">{{ $copy['labels']['checkin'] }}</div>
                <div style="color: #292524;">{{ $booking->arrival?->format('Y-m-d') }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 0; border-bottom: 1px solid #e7e5e4;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 4px;">{{ $copy['labels']['checkout'] }}</div>
                <div style="color: #292524;">{{ $booking->departure?->format('Y-m-d') }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 0; border-bottom: 1px solid #e7e5e4;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 4px;">{{ $copy['labels']['guests'] }}</div>
                <div style="color: #292524;">{{ ($booking->num_adult ?? 0) + ($booking->num_child ?? 0) }}</div>
            </td>
        </tr>
    </table>

    <p style="margin: 24px 0; line-height: 1.7; color: #57534e; font-size: 14px;">
        {{ $copy['guide'] }}
    </p>

    @if (($chatUrl ?? null) !== null || ($registrationUrl ?? null) !== null)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 0; border-top: 1px solid #e7e5e4;">
            @if (($chatUrl ?? null) !== null)
                <tr>
                    <td style="padding: 16px 0;">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 6px;">{{ $copy['chat_label'] }}</div>
                        <div style="margin-bottom: 6px;"><a href="{{ $chatUrl }}" style="color: #1c1917; word-break: break-all;">{{ $chatUrl }}</a></div>
                        <div style="font-size: 13px; color: #57534e; line-height: 1.6;">{{ $copy['chat_hint'] }}</div>
                    </td>
                </tr>
            @endif
            @if (($registrationUrl ?? null) !== null)
                <tr>
                    <td style="padding: 16px 0;{{ ($chatUrl ?? null) !== null ? ' border-top: 1px solid #e7e5e4;' : '' }}">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 6px;">{{ $copy['registration_label'] }}</div>
                        <div style="margin-bottom: 6px;"><a href="{{ $registrationUrl }}" style="color: #1c1917; word-break: break-all;">{{ $registrationUrl }}</a></div>
                        <div style="font-size: 13px; color: #57534e; line-height: 1.6;">{{ $copy['registration_hint'] }}</div>
                    </td>
                </tr>
            @endif
        </table>
    @endif

    <p style="margin: 32px 0 0; line-height: 1.7; color: #78716c;">
        {{ $copy['signoff'] }}
    </p>
</x-emails.layouts.base>
