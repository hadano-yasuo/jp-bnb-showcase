<?php

namespace App\Jobs\Beds24;

use App\Jobs\AI\TranslateGuestMessageJob;
use App\Models\Beds24Credential;
use App\Models\Beds24SyncLog;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Property;
use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * beds24 の /bookings/messages を newer-than-known パターンで同期する Job。
 *
 * - paginate() で id DESC 順に取得
 * - beds24_message_id で存在確認、既知に当たった時点で全体を打ち切り
 *   （id DESC なので以降は全て既知）
 * - 統計を Beds24SyncLog に記録
 * - WithoutOverlapping で並列実行防止（scheduler 重複起動対策）
 */
class SyncMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('beds24-sync-messages'))->expireAfter(300)];
    }

    public function handle(Beds24Client $client): void
    {
        $credential = Beds24Credential::query()
            ->where('name', 'self')
            ->where('is_active', true)
            ->first();

        if ($credential === null) {
            throw new RuntimeException(
                'Beds24Credential name=self が未登録。Beds24CredentialSeeder を実行してください。'
            );
        }

        $log = Beds24SyncLog::create([
            'resource' => Beds24SyncLog::RESOURCE_MESSAGES,
            'status' => Beds24SyncLog::STATUS_RUNNING,
            'credential_id' => $credential->id,
            'started_at' => now(),
        ]);

        try {
            [$fetched, $created, $pages] = $this->syncPages($client);

            $log->update([
                'status' => Beds24SyncLog::STATUS_SUCCESS,
                'completed_at' => now(),
                'records_fetched' => $fetched,
                'records_created' => $created,
                'pages_fetched' => $pages,
            ]);

            Log::info('beds24 messages sync completed', [
                'fetched' => $fetched,
                'created' => $created,
                'pages' => $pages,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => Beds24SyncLog::STATUS_FAILURE,
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'context' => $e instanceof Beds24Exception
                    ? ['http_status' => $e->httpStatus, 'context' => $e->context]
                    : ['exception' => get_class($e)],
            ]);
            throw $e;
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int} [fetched, created, pages]
     */
    private function syncPages(Beds24Client $client): array
    {
        $fetched = 0;
        $created = 0;
        $pages = 0;

        foreach ($client->paginate('/bookings/messages') as $page) {
            $pages++;

            foreach ($page['data'] as $msg) {
                $fetched++;

                $exists = Message::query()
                    ->where('beds24_message_id', $msg['id'])
                    ->exists();

                if ($exists) {
                    return [$fetched, $created, $pages];
                }

                $this->createMessage($msg);
                $created++;
            }
        }

        return [$fetched, $created, $pages];
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    private function createMessage(array $msg): void
    {
        $this->ensureListingExists(
            beds24RoomId: (int) $msg['roomId'],
            beds24PropertyId: (int) $msg['propertyId'],
        );

        $created = Message::create([
            'beds24_message_id' => $msg['id'],
            'beds24_booking_id' => $msg['bookingId'],
            'beds24_property_id' => $msg['propertyId'],
            'beds24_room_id' => $msg['roomId'],
            'author_owner_id' => $msg['authorOwnerId'] ?? null,
            'source' => $msg['source'],
            'message' => $msg['message'],
            'is_read' => $msg['read'] ?? false,
            'sent_at' => $msg['time'],
            'raw_payload' => $msg,
            'fetched_at' => now(),
        ]);

        // 新規 guest / host メッセージは非同期で言語検出+日本語訳（system は対象外）
        if ($created->source !== Message::SOURCE_SYSTEM) {
            TranslateGuestMessageJob::dispatch($created->id);
        }
    }

    /**
     * 未知の beds24 room / property なら Listing / Property をスケルトンで作成。
     * admin は後で Filament で building 割当・display_name 編集する。
     */
    private function ensureListingExists(int $beds24RoomId, int $beds24PropertyId): void
    {
        if (Listing::query()->where('beds24_room_id', $beds24RoomId)->exists()) {
            return;
        }

        Property::query()->firstOrCreate(
            ['beds24_property_id' => $beds24PropertyId],
            [
                'name' => "beds24 property {$beds24PropertyId}",
                'display_name' => "beds24 property {$beds24PropertyId}",
                'is_active' => true,
            ],
        );

        Listing::create([
            'beds24_room_id' => $beds24RoomId,
            'beds24_property_id' => $beds24PropertyId,
            'name' => "beds24 room {$beds24RoomId}",
            'display_name' => "beds24 room {$beds24RoomId}",
            'is_active' => true,
        ]);
    }
}
