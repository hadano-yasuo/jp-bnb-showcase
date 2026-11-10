<?php

namespace App\Console\Commands\Beds24;

use App\Jobs\Beds24\SyncBookingsJob;
use App\Models\Beds24SyncLog;
use Illuminate\Console\Command;

class SyncBookingsCommand extends Command
{
    protected $signature = 'beds24:sync-bookings';

    protected $description = 'beds24 の /bookings を全件 upsert（手動実行用）';

    public function handle(): int
    {
        $this->info('beds24 bookings sync 開始...');

        SyncBookingsJob::dispatchSync();

        $log = Beds24SyncLog::query()
            ->where('resource', Beds24SyncLog::RESOURCE_BOOKINGS)
            ->latest('id')
            ->first();

        if ($log === null) {
            $this->warn('sync log が見つかりません');

            return self::FAILURE;
        }

        $this->table(
            ['status', 'fetched', 'created', 'updated', 'pages', 'started', 'completed'],
            [[
                $log->status,
                $log->records_fetched,
                $log->records_created,
                $log->records_updated,
                $log->pages_fetched,
                $log->started_at->toDateTimeString(),
                $log->completed_at?->toDateTimeString() ?? '-',
            ]],
        );

        if ($log->status !== Beds24SyncLog::STATUS_SUCCESS) {
            $this->error('sync failed: '.($log->error_message ?? 'unknown'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
