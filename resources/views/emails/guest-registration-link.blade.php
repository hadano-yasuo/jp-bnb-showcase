<x-emails.layouts.base preheader="Sample Stay からのご案内">
    <div style="color: #292524; line-height: 1.8; white-space: pre-wrap; word-break: break-word;">{{ $bodyRendered }}</div>

    @if($includeNoReplyFooter)
        <hr style="margin: 32px 0 16px; border: 0; border-top: 1px solid #e7e5e4;">
        <p style="margin: 0; font-size: 12px; color: #a8a29e; line-height: 1.6;">
            ※ このメールは送信専用です。ご返信いただいてもホストに届きませんのでご了承ください。<br>
            ※ This email is send-only. Replies will not reach the host.
        </p>
    @endif
</x-emails.layouts.base>
