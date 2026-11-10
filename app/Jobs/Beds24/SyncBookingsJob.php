<?php

namespace App\Jobs\Beds24;

use App\Models\Beds24Credential;
use App\Models\Beds24SyncLog;
use App\Models\Booking;
use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\BookingUpserter;
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
 * beds24 の /bookings を同期する Job。
 *
 * - modifiedSince が /bookings で機能するか未確認のため（docs 章6・要検証）、
 *   まずは全件取得 + upsert のシンプルな戦略。
 * - modifiedFrom が使えれば differential sync に切り替え可能（TODO）。
 * - upsert キー: beds24_booking_id
 * - 統計を Beds24SyncLog に記録
 * - WithoutOverlapping で並列実行防止
 */
class SyncBookingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

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
        return [(new WithoutOverlapping('beds24-sync-bookings'))->expireAfter(600)];
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
            'resource' => Beds24SyncLog::RESOURCE_BOOKINGS,
            'status' => Beds24SyncLog::STATUS_RUNNING,
            'credential_id' => $credential->id,
            'started_at' => now(),
        ]);

        try {
            [$fetched, $created, $updated, $pages] = $this->syncPages($client);

            $log->update([
                'status' => Beds24SyncLog::STATUS_SUCCESS,
                'completed_at' => now(),
                'records_fetched' => $fetched,
                'records_created' => $created,
                'records_updated' => $updated,
                'pages_fetched' => $pages,
            ]);

            Log::info('beds24 bookings sync completed', [
                'fetched' => $fetched,
                'created' => $created,
                'updated' => $updated,
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
     * @return array{0: int, 1: int, 2: int, 3: int} [fetched, created, updated, pages]
     */
    private function syncPages(Beds24Client $client): array
    {
        $fetched = 0;
        $created = 0;
        $updated = 0;
        $pages = 0;

        // beds24 の /bookings はデフォルトで confirmed/request/new/black/inquiry しか
        // 返さない（cancelled は除外）。カレンダーでキャンセル予約も表示するため
        // status を明示指定して全ステータスを取得する。
        // 過去の会話にも booking 情報（ゲスト名等）が必要なので arrivalFrom で3年前から。
        $query = [
            'arrivalFrom' => now()->subYears(3)->toDateString(),
            'status' => ['confirmed', 'request', 'new', 'cancelled', 'black', 'inquiry'],
        ];

        $upserter = app(BookingUpserter::class);

        foreach ($client->paginate('/bookings', $query) as $page) {
            $pages++;

            foreach ($page['data'] as $booking) {
                $fetched++;

                $existing = Booking::query()
                    ->where('beds24_booking_id', $booking['id'])
                    ->first();

                $upserter->upsert($booking);

                if ($existing === null) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        }

        return [$fetched, $created, $updated, $pages];
    }
}
