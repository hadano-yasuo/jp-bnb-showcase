<?php

namespace App\Services\Beds24;

use App\Models\Message;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * メッセージ既読化の集約サービス。
 *
 * - local `messages.is_read` の更新
 * - beds24 側への `POST /bookings/messages` 同期（best-effort）
 *
 * beds24 の `read` は「ホスト（管理者）側の開封状態」を示す（docs 章5.4）。
 * beds24 API は POST が create / update 兼用で、`id` 付きだと update 動作
 * （2026-09-07 実測、PATCH エンドポイントは "Invalid data" で機能しない）。
 * `write:bookings-personal` スコープ要。500件ずつ chunk で送信。
 *
 * 失敗時のポリシー:
 * - request 自体が例外 → local update は継続、log に警告
 * - 個別 item の success:false → 該当分だけ log、他は成功扱い
 * - local 更新は常に実行（beds24 一時障害で UI が古いまま止まるのを避ける）
 */
class MessageReadSyncer
{
    private const BEDS24_UPDATE_CHUNK = 500;

    public function __construct(private readonly Beds24Client $client) {}

    /**
     * 未読メッセージを既読化する。既に is_read=true のものは skip。
     *
     * @param  iterable<Message>  $messages
     * @return array{local_updated: int, beds24_synced: int, beds24_failed: int}
     */
    public function markAsRead(iterable $messages, bool $syncToBeds24 = true): array
    {
        $unread = collect($messages)->reject(fn (Message $m) => $m->is_read)->values();

        if ($unread->isEmpty()) {
            return ['local_updated' => 0, 'beds24_synced' => 0, 'beds24_failed' => 0];
        }

        [$synced, $failed] = $syncToBeds24
            ? $this->pushReadStatusToBeds24($unread->filter(fn (Message $m) => $m->source === Message::SOURCE_GUEST)->values())
            : [0, 0];

        $localUpdated = Message::query()
            ->whereIn('id', $unread->pluck('id')->all())
            ->update(['is_read' => true]);

        return [
            'local_updated' => $localUpdated,
            'beds24_synced' => $synced,
            'beds24_failed' => $failed,
        ];
    }

    /**
     * POST /bookings/messages に `[{id, read: true}]` を送って既読フラグを更新する。
     *
     * @param  Collection<int, Message>  $guestMessages
     * @return array{0: int, 1: int} [synced, failed]
     */
    private function pushReadStatusToBeds24(Collection $guestMessages): array
    {
        if ($guestMessages->isEmpty()) {
            return [0, 0];
        }

        $synced = 0;
        $failed = 0;

        foreach ($guestMessages->chunk(self::BEDS24_UPDATE_CHUNK) as $chunk) {
            $chunkList = $chunk->values();
            $items = $chunkList->map(fn (Message $m) => [
                'id' => $m->beds24_message_id,
                'read' => true,
            ])->all();

            try {
                $response = $this->client->post('/bookings/messages', $items);
            } catch (Beds24Exception $e) {
                Log::warning('beds24 mark-as-read request failed entirely', [
                    'chunk_size' => $chunkList->count(),
                    'error' => $e->getMessage(),
                    'context' => $e->context,
                ]);
                $failed += $chunkList->count();

                continue;
            }

            foreach ($response as $i => $result) {
                if (($result['success'] ?? false) === true) {
                    $synced++;
                } else {
                    $failed++;
                    Log::warning('beds24 mark-as-read item failed', [
                        'beds24_message_id' => $chunkList[$i]->beds24_message_id ?? null,
                        'response' => $result,
                    ]);
                }
            }
        }

        return [$synced, $failed];
    }
}
