<?php

namespace App\Jobs\Beds24;

use App\Models\Beds24Credential;
use App\Models\Beds24SyncLog;
use App\Models\Listing;
use App\Models\RoomBlock;
use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * beds24 の /inventory/rooms/availability から availability=false 日を
 * room_blocks テーブルへ upsert する。
 *
 * beds24 のブロックは「booking with status=black」ではなく「calendar UI で
 * availability を false にする」形で運用されているため /bookings では取れない。
 *
 * 表示側で bookings と突合するので、ここでは false 日を素直に保存するだけ。
 *
 * 対象範囲: 昨日〜今日+365日（過去は積み残す意味が薄いので 1日だけ）
 * リクエスト頻度: 1 sync = active listing 数 × 1 API call
 */
class SyncRoomBlocksJob implements ShouldQueue
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
        return [(new WithoutOverlapping('beds24-sync-room-blocks'))->expireAfter(600)];
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
            'resource' => Beds24SyncLog::RESOURCE_ROOM_BLOCKS,
            'status' => Beds24SyncLog::STATUS_RUNNING,
            'credential_id' => $credential->id,
            'started_at' => now(),
        ]);

        try {
            [$fetched, $created, $updated] = $this->syncListings($client);

            $log->update([
                'status' => Beds24SyncLog::STATUS_SUCCESS,
                'completed_at' => now(),
                'records_fetched' => $fetched,
                'records_created' => $created,
                'records_updated' => $updated,
            ]);

            Log::info('beds24 room blocks sync completed', [
                'fetched' => $fetched,
                'created' => $created,
                'updated' => $updated,
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
     * @return array{0: int, 1: int, 2: int} [fetched, created, updated]
     */
    private function syncListings(Beds24Client $client): array
    {
        $startDate = Carbon::yesterday()->toDateString();
        $endDate = Carbon::today()->addDays(365)->toDateString();

        $fetched = 0;
        $created = 0;
        $updated = 0;

        $roomIds = Listing::query()->where('is_active', true)->pluck('beds24_room_id')->all();

        foreach ($roomIds as $roomId) {
            $response = $client->get('/inventory/rooms/availability', [
                'roomId' => $roomId,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]);

            /** @var array<int, array{roomId: int, availability: array<string, bool>}> $data */
            $data = $response['data'];
            foreach ($data as $row) {
                $availability = $row['availability'];
                $fetched += count($availability);

                $blockedDates = array_keys(array_filter($availability, fn (bool $v): bool => $v === false));

                DB::transaction(function () use ($roomId, $startDate, $endDate, $blockedDates, &$created, &$updated) {
                    $existing = RoomBlock::query()
                        ->where('beds24_room_id', $roomId)
                        ->whereBetween('date', [$startDate, $endDate])
                        ->pluck('date')
                        ->map(fn (Carbon $d): string => $d->toDateString())
                        ->all();
                    $existingSet = array_fill_keys($existing, true);

                    $newSet = array_fill_keys($blockedDates, true);

                    $toDelete = array_diff_key($existingSet, $newSet);
                    if ($toDelete !== []) {
                        RoomBlock::query()
                            ->where('beds24_room_id', $roomId)
                            ->whereIn('date', array_keys($toDelete))
                            ->delete();
                    }

                    $toCreate = array_diff_key($newSet, $existingSet);
                    $now = now();
                    foreach (array_keys($toCreate) as $date) {
                        RoomBlock::create([
                            'beds24_room_id' => $roomId,
                            'date' => $date,
                            'fetched_at' => $now,
                        ]);
                        $created++;
                    }

                    $toRefresh = array_intersect_key($existingSet, $newSet);
                    if ($toRefresh !== []) {
                        RoomBlock::query()
                            ->where('beds24_room_id', $roomId)
                            ->whereIn('date', array_keys($toRefresh))
                            ->update(['fetched_at' => $now]);
                        $updated += count($toRefresh);
                    }
                });
            }
        }

        return [$fetched, $created, $updated];
    }
}
