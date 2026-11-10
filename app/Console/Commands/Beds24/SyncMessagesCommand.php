<?php

namespace App\Console\Commands\Beds24;

use App\Jobs\Beds24\SyncMessagesJob;
use App\Models\Beds24SyncLog;
use Illuminate\Console\Command;

class SyncMessagesCommand extends Command
{
    protected $signature = 'beds24:sync-messages';

    protected $description = 'beds24 の /bookings/messages を newer-than-known で同期（手動実行用）';

    public function handle(): int
    {
        $this->info('beds24 messages sync 開始...');

        SyncMessagesJob::dispatchSync();

        $log = Beds24SyncLog::query()
            ->where('resource', Beds24SyncLog::RESOURCE_MESSAGES)
            ->latest('id')
            ->first();

        if ($log === null) {
            $this->warn('sync log が見つかりません');

            return self::FAILURE;
        }

        $this->table(
            ['status', 'fetched', 'created', 'pages', 'started', 'completed'],
            [[
                $log->status,
                $log->records_fetched,
                $log->records_created,
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
