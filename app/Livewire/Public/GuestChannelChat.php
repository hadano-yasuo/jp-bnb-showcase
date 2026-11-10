<?php

namespace App\Livewire\Public;

use App\Jobs\AI\TranslateGuestChannelMessageJob;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use App\Models\Message;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 公開HP /chat/{token} のチャット画面。
 *
 * - トークンで GuestChannel を解決 (無効/期限切れは 404)
 * - 予約前: GuestChannelMessage を読み書き
 * - 予約後 (channel.booking_id あり):
 *   - 表示は Message テーブル (問い合わせ履歴 + 予約確定 + auto-message + ホスト返信を統合)
 *     ※ 予約成立時に GuestChannelMessage は Message に移送済み
 *   - ゲスト投稿も Message に書く (admin ShowConversation と同じソース)
 * - ホスト返信・新規メッセージは wire:poll で自動反映
 * - 「予約する」ボタンは予約前のみ表示 (予約済みなら不要)
 */
class GuestChannelChat extends Component
{
    #[Locked]
    public GuestChannel $channel;

    public string $body = '';

    public function mount(string $token): void
    {
        $channel = GuestChannel::query()
            ->where('token', $token)
            ->first();

        if ($channel === null || $channel->isExpired()) {
            throw new NotFoundHttpException('guest channel not found or expired');
        }

        $this->channel = $channel;
    }

    public function sendMessage(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($this->channel->isExpired()) {
            $this->addError('body', 'このチャットは有効期限が切れています。');

            return;
        }

        $text = trim($this->body);

        // 予約前は GuestChannelMessage、予約後は Message (admin ShowConversation と同じソース) に書く。
        if ($this->channel->booking_id === null) {
            $message = GuestChannelMessage::create([
                'guest_channel_id' => $this->channel->id,
                'source' => GuestChannelMessage::SOURCE_GUEST,
                'message' => $text,
                'sent_at' => now(),
            ]);
            TranslateGuestChannelMessageJob::dispatch($message->id);
        } else {
            $booking = $this->channel->booking;
            if ($booking === null) {
                $this->addError('body', '予約情報が確認できません。');

                return;
            }
            Message::create([
                'beds24_message_id' => null,
                'beds24_booking_id' => $booking->beds24_booking_id,
                'beds24_property_id' => $booking->beds24_property_id,
                'beds24_room_id' => $booking->beds24_room_id,
                'source' => Message::SOURCE_GUEST,
                'message' => $text,
                'is_read' => false,
                'sent_at' => now(),
                'fetched_at' => now(),
                'raw_payload' => [],
            ]);
        }

        $this->body = '';
    }

    public function render(): View
    {
        return view('livewire.public.guest-channel-chat', [
            'messages' => $this->fetchMessages(),
            // 「予約する」ボタンから /reserve/direct へ遷移し inquiry_token を保持する。
            // 予約前/後で分岐しない (UI 一貫性のため常時表示、再予約も許容)。
            'reserveUrl' => '/reserve/direct?inquiry='.$this->channel->token,
        ]);
    }

    /**
     * 予約前: GuestChannelMessage。予約後: Booking に紐づく Message。
     * ($msg->isFromGuest / isFromHost / message / sent_at は両モデルに存在する)
     *
     * @return iterable<int, GuestChannelMessage|Message>
     */
    private function fetchMessages(): iterable
    {
        if ($this->channel->booking_id === null) {
            return $this->channel->messages()->get();
        }

        $booking = $this->channel->booking;
        if ($booking === null) {
            return collect();
        }

        return Message::query()
            ->where('beds24_booking_id', $booking->beds24_booking_id)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }
}
