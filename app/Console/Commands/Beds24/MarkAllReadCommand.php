<?php

namespace App\Console\Commands\Beds24;

use App\Models\Message;
use App\Services\Beds24\MessageReadSyncer;
use Illuminate\Console\Command;

/**
 * 過去に AssistBnB が同期してこなかった guest メッセージを一括既読化するリセット用コマンド。
 *
 * 通常運用では Filament で開いた時 or BulkAction で既読化されるので、
 * これは jp-bnb 移行時 or 手動リセット時の一回きり用途。
 */
class MarkAllReadCommand extends Command
{
    protected $signature = 'beds24:mark-all-read
        {--dry-run : 対象件数だけ表示して実行しない}
        {--local-only : beds24 側への同期をせず local `is_read` のみ更新}
        {--source=guest : 対象 source（guest/host/system）}';

    protected $description = '未読メッセージを一括既読化（デフォルト: 全 guest 未読）';

    public function handle(MessageReadSyncer $syncer): int
    {
        $source = (string) $this->option('source');
        $query = Message::query()
            ->where('is_read', false)
            ->where('source', $source);

        $count = $query->count();

        $this->info("対象: source={$source}, 未読件数={$count}");

        if ($count === 0) {
            $this->line('既読化する対象がありません。');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run 指定のため、実際の更新はしません。');

            return self::SUCCESS;
        }

        $localOnly = (bool) $this->option('local-only');
        $mode = $localOnly ? 'local のみ' : 'local + beds24 同期';

        if (! $this->confirm("{$count}件を既読化します（{$mode}）。続けますか？", true)) {
            $this->warn('中断しました。');

            return self::FAILURE;
        }

        $messages = $query->get();

        $this->info('既読化中...');
        $result = $syncer->markAsRead($messages, syncToBeds24: ! $localOnly);

        $this->table(
            ['local 更新', 'beds24 同期成功', 'beds24 同期失敗'],
            [[
                $result['local_updated'],
                $result['beds24_synced'],
                $result['beds24_failed'],
            ]],
        );

        if ($result['beds24_failed'] > 0) {
            $this->warn("beds24 同期に失敗した {$result['beds24_failed']}件は local のみ既読化されています。詳細は storage/logs/laravel.log 参照。");
        }

        return self::SUCCESS;
    }
}
