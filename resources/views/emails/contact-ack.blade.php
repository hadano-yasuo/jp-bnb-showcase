@php
    // ロケール別コピー (Mailable 側で App::setLocale 済みなので $locale と一致)
    $copy = match ($locale) {
        'en' => [
            'preheader' => 'We have received your inquiry.',
            'heading' => 'Thank you for reaching out',
            'greeting' => 'Dear '.($guestName !== '' ? $guestName : 'Guest').',',
            'body' => 'Thank you for contacting Sample Stay. We have received your message and will get back to you within 24 hours.',
            'recap_label' => 'YOUR MESSAGE',
            'closing' => 'We look forward to welcoming you.',
            'signoff' => 'Sample Stay Team',
        ],
        'es' => [
            'preheader' => 'Hemos recibido su consulta.',
            'heading' => 'Gracias por su mensaje',
            'greeting' => 'Estimado/a '.($guestName !== '' ? $guestName : 'huésped').',',
            'body' => 'Gracias por contactar con Sample Stay. Hemos recibido su mensaje y responderemos en un plazo de 24 horas.',
            'recap_label' => 'SU MENSAJE',
            'closing' => 'Esperamos darle la bienvenida.',
            'signoff' => 'El equipo de Sample Stay',
        ],
        'zh' => [
            'preheader' => '我们已收到您的咨询。',
            'heading' => '感谢您的联系',
            'greeting' => '尊敬的 '.($guestName !== '' ? $guestName : '客人').' ：',
            'body' => '感谢您联系 Sample Stay。我们已收到您的信息，将在 24 小时内回复。',
            'recap_label' => '您的信息',
            'closing' => '期待与您相会。',
            'signoff' => 'Sample Stay 团队',
        ],
        'ko' => [
            'preheader' => '문의를 접수하였습니다.',
            'heading' => '문의해 주셔서 감사합니다',
            'greeting' => ($guestName !== '' ? $guestName : '고객') . ' 님 안녕하세요,',
            'body' => 'Sample Stay에 문의해 주셔서 감사합니다. 메시지를 확인하였으며 24시간 이내에 답변드리겠습니다.',
            'recap_label' => '문의 내용',
            'closing' => '만나 뵙기를 기대하겠습니다.',
            'signoff' => 'Sample Stay 팀',
        ],
        default => [
            'preheader' => 'お問い合わせを受け付けました。',
            'heading' => 'お問い合わせありがとうございます',
            'greeting' => ($guestName !== '' ? $guestName : 'お客様') . ' 様',
            'body' => 'Sample Stay へお問い合わせいただきありがとうございます。<br>内容を確認のうえ、24 時間以内にご返信いたします。',
            'recap_label' => 'いただいたメッセージ',
            'closing' => 'お会いできる日を楽しみにしております。',
            'signoff' => 'Sample Stay スタッフ一同',
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
        {!! $copy['body'] !!}
    </p>

    @if($guestMessage !== '')
        <div style="margin: 24px 0; padding: 20px; background-color: #faf6ee; border-left: 3px solid #8b1a1a;">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #78716c; margin-bottom: 12px;">
                {{ $copy['recap_label'] }}
            </div>
            <div style="color: #292524; line-height: 1.7; white-space: pre-wrap; word-break: break-word;">
                {{ $guestMessage }}
            </div>
        </div>
    @endif

    @if(($chatUrl ?? '') !== '')
        @php
            $chatLabels = match ($locale) {
                'en' => ['heading' => 'Continue in chat', 'body' => 'You can reply, ask follow-ups, and move to booking — all from the private link below.', 'cta' => 'OPEN CHAT'],
                'es' => ['heading' => 'Continuar en el chat', 'body' => 'Puede responder, hacer preguntas de seguimiento y proceder a la reserva desde el enlace privado a continuación.', 'cta' => 'ABRIR CHAT'],
                'zh' => ['heading' => '在聊天中继续', 'body' => '您可以通过下面的专属链接回复、追加提问、直接预订。', 'cta' => '打开聊天'],
                'ko' => ['heading' => '채팅에서 계속하기', 'body' => '아래 전용 링크에서 답장, 추가 질문, 예약까지 이어서 진행하실 수 있습니다.', 'cta' => '채팅 열기'],
                default => ['heading' => 'チャットで続きを', 'body' => '下記の専用リンクから、返信・追加のご質問・そのままご予約までお進みいただけます。', 'cta' => 'チャットを開く'],
            };
        @endphp
        <div style="margin: 32px 0; padding: 24px; background-color: #ffffff; border: 1px solid #e7e5e4; text-align: center;">
            <p style="margin: 0 0 8px; font-family: 'Playfair Display', Georgia, serif; font-size: 18px; color: #1c1917;">
                {{ $chatLabels['heading'] }}
            </p>
            <p style="margin: 0 0 20px; font-size: 13px; color: #57534e; line-height: 1.7;">
                {{ $chatLabels['body'] }}
            </p>
            <a href="{{ $chatUrl }}" style="display: inline-block; padding: 12px 32px; background-color: #8b1a1a; color: #ffffff; text-decoration: none; font-size: 12px; letter-spacing: 0.2em; text-transform: uppercase;">
                {{ $chatLabels['cta'] }}
            </a>
            <p style="margin: 16px 0 0; font-size: 11px; color: #a8a29e; word-break: break-all;">
                {{ $chatUrl }}
            </p>
        </div>
    @endif

    <p style="margin: 32px 0 8px; line-height: 1.7;">
        {{ $copy['closing'] }}
    </p>
    <p style="margin: 0; line-height: 1.7; color: #78716c;">
        {{ $copy['signoff'] }}
    </p>
</x-emails.layouts.base>
