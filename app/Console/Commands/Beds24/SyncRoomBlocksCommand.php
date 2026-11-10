<?php

namespace App\Console\Commands\Beds24;

use App\Jobs\Beds24\SyncRoomBlocksJob;
use App\Models\Beds24SyncLog;
use Illuminate\Console\Command;

class SyncRoomBlocksCommand extends Command
{
    protected $signature = 'beds24:sync-room-blocks';

    protected $description = 'beds24 の /inventory/rooms/availability から false 日を room_blocks に upsert';

    public function handle(): int
    {
        $this->info('beds24 room blocks sync 開始...');

        SyncRoomBlocksJob::dispatchSync();

        $log = Beds24SyncLog::query()
            ->where('resource', Beds24SyncLog::RESOURCE_ROOM_BLOCKS)
            ->latest('id')
            ->first();

        if ($log === null) {
            $this->warn('sync log が見つかりません');

            return self::FAILURE;
        }

        $this->table(
            ['status', 'fetched', 'created', 'updated', 'started', 'completed'],
            [[
                $log->status,
                $log->records_fetched,
                $log->records_created,
                $log->records_updated,
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
