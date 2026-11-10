<?php

namespace App\Jobs\AutoMessage;

use App\Services\AutoMessage\AutoMessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 1 分毎 cron。scheduled_at を過ぎた pending 送信を処理。
 * 現段階では dry-run のみ実装（実送信は未実装、AutoMessageDispatcher 参照）。
 */
class DispatchScheduledAutoMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('auto-message-dispatch'))->expireAfter(120)];
    }

    public function handle(AutoMessageDispatcher $dispatcher): void
    {
        $result = $dispatcher->dispatchDue();

        if ($result['processed'] > 0) {
            Log::info('auto_message dispatch completed', $result);
        }
    }
}
