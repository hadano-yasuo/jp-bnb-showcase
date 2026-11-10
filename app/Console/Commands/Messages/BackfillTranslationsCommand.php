<?php

namespace App\Console\Commands\Messages;

use App\Jobs\AI\TranslateGuestMessageJob;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * 過去の guest メッセージについて、日本語訳が未生成のものを一括で翻訳する。
 *
 * デフォルトは queue 投入。--sync で同期実行（少数件のデバッグ用）。
 */
class BackfillTranslationsCommand extends Command
{
    protected $signature = 'messages:backfill-translations {--sync : 同期実行（少数件のデバッグ用）} {--limit= : 対象件数の上限} {--force : 既存の message_ja も上書き（プロンプト変更後の再翻訳等）} {--ids= : カンマ区切りの Message id を指定（デバッグ用）} {--source= : 対象 source を絞る（guest/host、未指定なら両方）}';

    protected $description = '過去のメッセージで message_ja が空のものを翻訳（言語検出も自動、system は対象外）';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $source = $this->option('source');

        $baseQuery = Message::query()
            ->where('source', '!=', Message::SOURCE_SYSTEM)
            // detected_language が 'ja' のものは翻訳不要（TranslateGuestMessageJob 側でも skip 判定）
            ->where(function ($q) {
                $q->whereNull('detected_language')
                    ->orWhere('detected_language', '!=', 'ja');
            });

        if ($source !== null) {
            $baseQuery->where('source', (string) $source);
        }

        if (! $force) {
            $baseQuery->whereNull('message_ja');
        }

        if ($this->option('ids') !== null) {
            $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));
            $baseQuery->whereIn('id', $ids);
        }

        if ($force) {
            // 既存 message_ja を先にクリアして Job 側の冪等スキップを無効化
            (clone $baseQuery)->update(['message_ja' => null]);
        }

        $matched = $baseQuery->count();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $total = $limit !== null ? min($limit, $matched) : $matched;

        $this->info("対象候補: {$matched} 件 / 今回処理: {$total} 件");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $ids = $baseQuery->orderBy('id')->limit($total)->pluck('id');

        $sync = (bool) $this->option('sync');
        $dispatched = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($ids as $id) {
            if ($sync) {
                TranslateGuestMessageJob::dispatchSync((int) $id);
            } else {
                TranslateGuestMessageJob::dispatch((int) $id);
            }
            $dispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info("完了: {$dispatched} 件を同期処理");
        } else {
            $this->info("キュー投入: {$dispatched} 件（queue worker で処理されます）");
        }

        return self::SUCCESS;
    }
}
