<?php

namespace App\Http\Controllers;

use App\Filament\Pages\GuestChannels\ShowGuestChannel;
use App\Jobs\AI\TranslateGuestChannelMessageJob;
use App\Mail\ContactAckMail;
use App\Mail\ContactSubmissionMail;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use App\Support\PublicUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 公開HP 問い合わせフォーム。
 *
 * flow (memory: project_self_hp_channel):
 *   GET  /contact         フォーム表示
 *   POST /contact         validate → GuestChannel + 初回 message → 2通メール送信 → /chat/{token} redirect
 */
class ContactController extends Controller
{
    public function show(): View
    {
        return view('public.contact.show');
    }

    public function store(Request $request): RedirectResponse
    {
        $roomSlugs = array_keys(config('rooms'));
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'room_slug' => ['nullable', 'string', 'in:'.implode(',', $roomSlugs)],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $roomName = ! empty($validated['room_slug'])
            ? (string) config('rooms.'.$validated['room_slug'].'.name')
            : null;

        /** @var GuestChannel $channel */
        $channel = DB::transaction(function () use ($validated, $roomName) {
            $channel = GuestChannel::create([
                'token' => GuestChannel::generateToken(),
                'guest_name' => $validated['name'] ?? null,
                'guest_email' => $validated['email'],
                'guest_locale' => App::getLocale(),
                'room_slug' => $validated['room_slug'] ?? null,
                'expires_at' => now()->addDays(GuestChannel::DEFAULT_EXPIRES_DAYS),
            ]);

            if ($roomName !== null) {
                GuestChannelMessage::create([
                    'guest_channel_id' => $channel->id,
                    'source' => GuestChannelMessage::SOURCE_SYSTEM,
                    'message' => '対象の部屋: '.$roomName,
                    'sent_at' => now(),
                ]);
            }

            GuestChannelMessage::create([
                'guest_channel_id' => $channel->id,
                'source' => GuestChannelMessage::SOURCE_GUEST,
                'message' => $validated['message'],
                'sent_at' => now(),
            ]);

            return $channel;
        });

        $this->sendNotifications($channel, $roomName);

        return redirect()->route('chat.show', ['token' => $channel->token]);
    }

    private function sendNotifications(GuestChannel $channel, ?string $roomName): void
    {
        $guestMessage = $channel->messages()
            ->where('source', GuestChannelMessage::SOURCE_GUEST)
            ->orderBy('id')
            ->firstOrFail();

        TranslateGuestChannelMessageJob::dispatch($guestMessage->id);

        Mail::to($channel->guest_email)
            ->send(new ContactAckMail(
                guestName: $channel->guest_name ?? '',
                guestMessage: $guestMessage->message,
                guestLocale: $channel->guest_locale,
                chatUrl: PublicUrl::route('chat.show', ['token' => $channel->token]),
            ));

        $data = [
            '氏名' => $channel->guest_name ?? '(未入力)',
            'メール' => $channel->guest_email,
            '言語' => $channel->guest_locale,
        ];

        if ($roomName !== null) {
            $data['対象の部屋'] = $roomName;
        }

        $data['メッセージ'] = $guestMessage->message;

        Mail::to(config('mail.from.address'))
            ->send(new ContactSubmissionMail(
                data: $data,
                adminUrl: ShowGuestChannel::getUrl(['channelId' => $channel->id]),
            ));
    }
}
