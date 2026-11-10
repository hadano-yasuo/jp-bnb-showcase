@php
    $copy = match ($locale) {
        'en' => ['preheader' => 'A message from Sample Stay', 'chat_heading' => 'Continue in chat', 'chat_body' => 'Reply from the private link below.', 'chat_cta' => 'OPEN CHAT'],
        'ko' => ['preheader' => 'Sample Stay 안내', 'chat_heading' => '채팅에서 계속하기', 'chat_body' => '아래 전용 링크에서 이어서 진행하실 수 있습니다.', 'chat_cta' => '채팅 열기'],
        'zh' => ['preheader' => 'Sample Stay 的通知', 'chat_heading' => '在聊天中继续', 'chat_body' => '您可以通过下面的专属链接继续沟通。', 'chat_cta' => '打开聊天'],
        'es' => ['preheader' => 'Un mensaje de Sample Stay', 'chat_heading' => 'Continuar en el chat', 'chat_body' => 'Puede continuar desde el enlace privado a continuación.', 'chat_cta' => 'ABRIR CHAT'],
        default => ['preheader' => 'Sample Stay からのご案内', 'chat_heading' => 'チャットで続きを', 'chat_body' => '下記の専用リンクから返信・追加のご質問にお進みいただけます。', 'chat_cta' => 'チャットを開く'],
    };
@endphp

<x-emails.layouts.base :preheader="$copy['preheader']">
    <div style="color: #292524; line-height: 1.8; white-space: pre-wrap; word-break: break-word;">{!! \App\Models\Message::autoLinkUrls($bodyRendered) !!}</div>

    @if(! empty($chatUrl))
        <div style="margin: 32px 0 0; padding: 24px; background-color: #ffffff; border: 1px solid #e7e5e4; text-align: center;">
            <p style="margin: 0 0 8px; font-family: 'Playfair Display', Georgia, serif; font-size: 18px; color: #1c1917;">
                {{ $copy['chat_heading'] }}
            </p>
            <p style="margin: 0 0 20px; font-size: 13px; color: #57534e; line-height: 1.7;">
                {{ $copy['chat_body'] }}
            </p>
            <a href="{{ $chatUrl }}" target="_blank" style="display: inline-block; padding: 12px 32px; background-color: #8b1a1a; color: #ffffff; text-decoration: none; font-size: 12px; letter-spacing: 0.2em; text-transform: uppercase;">
                {{ $copy['chat_cta'] }}
            </a>
            <p style="margin: 16px 0 0; font-size: 11px; color: #a8a29e; word-break: break-all;">
                {{ $chatUrl }}
            </p>
        </div>
    @endif
</x-emails.layouts.base>
