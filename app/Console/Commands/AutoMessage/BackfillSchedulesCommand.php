<?php

namespace App\Console\Commands\AutoMessage;

use App\Models\Booking;
use App\Services\AutoMessage\AutoMessageScheduler;
use Illuminate\Console\Command;

/**
 * 既存の予約に対して AutoMessageScheduler::scheduleFor() を再実行する。
 *
 * 新規ルールを Filament で作った直後に走らせると、過去に upsert 済みの予約に
 * pending sends が生成される。Scheduler は unique(rule, booking) 制約で冪等。
 *
 * デフォルトは「今日以降にチェックインする予約」に絞る（過去分にリマインドを
 * 発火させても意味がないため）。--all で departure が過去のものも含める。
 */
class BackfillSchedulesCommand extends Command
{
    protected $signature = 'auto-message:backfill-schedules {--all : departure が過去の予約も対象に含める} {--limit= : 対象件数の上限}';

    protected $description = 'AutoMessageRule 追加後に既存予約へ pending sends を生成する (冪等)';

    public function handle(AutoMessageScheduler $scheduler): int
    {
        $query = Booking::query()->whereNotNull('beds24_room_id');

        if (! $this->option('all')) {
            $query->where(function ($q) {
                $q->whereNull('departure')
                    ->orWhere('departure', '>=', today());
            });
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $total = $limit !== null ? min($limit, $query->count()) : $query->count();

        $this->info("対象予約: {$total} 件");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $query->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->chunkById(100, function ($bookings) use ($scheduler, &$processed, $bar) {
                foreach ($bookings as $booking) {
                    $scheduler->scheduleFor($booking);
                    $processed++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("完了: {$processed} 件を Scheduler 再実行");

        return self::SUCCESS;
    }
}
