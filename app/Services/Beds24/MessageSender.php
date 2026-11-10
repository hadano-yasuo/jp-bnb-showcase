<?php

namespace App\Services\Beds24;

use App\Models\Booking;
use App\Models\Message;
use App\Services\Beds24\Exceptions\Beds24Exception;

/**
 * beds24 へのメッセージ送信 + local `messages` テーブルへの反映。
 *
 * beds24 POST 応答は `[{success, new: {id, ...}} | {success:false, errors:[...]}]` の形式で
 * per-item に成否を返す（docs/beds24-notes.md 章5）。呼び出し元がその都度確認する必要があるため
 * ここで吸収し、成功なら local Message を返し、失敗なら Beds24Exception を投げる。
 */
class MessageSender
{
    public function __construct(private readonly Beds24Client $client) {}

    /**
     * 既存メッセージへの返信。source は必ず "host"。
     * 返信元メッセージから property_id / room_id を継承する。
     */
    public function replyTo(Message $source, string $text): Message
    {
        return $this->send(
            beds24BookingId: $source->beds24_booking_id,
            beds24PropertyId: $source->beds24_property_id,
            beds24RoomId: $source->beds24_room_id,
            text: $text,
        );
    }

    /**
     * 予約に対して直接ホスト送信する。ゲストメッセージが 1 件もない場合
     * (自社サイト直接予約など) に使う。property_id / room_id は Booking から引く。
     */
    public function sendToBooking(Booking $booking, string $text): Message
    {
        return $this->send(
            beds24BookingId: $booking->beds24_booking_id,
            beds24PropertyId: $booking->beds24_property_id,
            beds24RoomId: $booking->beds24_room_id,
            text: $text,
        );
    }

    private function send(int $beds24BookingId, ?int $beds24PropertyId, ?int $beds24RoomId, string $text): Message
    {
        $response = $this->client->post('/bookings/messages', [
            [
                'bookingId' => $beds24BookingId,
                'message' => $text,
                'source' => Message::SOURCE_HOST,
            ],
        ]);

        $first = $response[0] ?? null;
        if (! is_array($first) || ($first['success'] ?? false) !== true) {
            throw new Beds24Exception(
                message: 'beds24 message POST failed',
                context: ['response' => $response],
            );
        }

        $new = $first['new'] ?? null;
        if (! is_array($new) || ! isset($new['id'])) {
            throw new Beds24Exception(
                message: 'beds24 POST response missing new.id',
                context: ['response' => $response],
            );
        }

        return Message::create([
            'beds24_message_id' => $new['id'],
            'beds24_booking_id' => $beds24BookingId,
            'beds24_property_id' => $beds24PropertyId,
            'beds24_room_id' => $beds24RoomId,
            'author_owner_id' => $new['authorOwnerId'] ?? null,
            'source' => Message::SOURCE_HOST,
            'message' => $text,
            'is_read' => true,
            'sent_at' => now(),
            'raw_payload' => $new,
            'fetched_at' => now(),
        ]);
    }
}
