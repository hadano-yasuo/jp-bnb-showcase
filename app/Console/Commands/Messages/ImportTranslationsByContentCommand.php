<?php

namespace App\Console\Commands\Messages;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * host メッセージのように同一本文が多数の row に散らばっている場合、
 * 内容 (message カラム) をキーに一括更新するためのコマンド。
 *
 * JSON の各行は {id, message?, detected_language, detected_language_label, message_ja}。
 * id は「代表 id」（実際の更新は message 一致で全 row に適用）。
 * message フィールドは省略可、なければ id から解決する。
 */
class ImportTranslationsByContentCommand extends Command
{
    protected $signature = 'messages:import-translations-by-content {path : JSON ファイルパス} {--source= : 対象 source を絞る（host/guest/system）}';

    protected $description = '同一本文の messages 全 row に対して message_ja / detected_language を一括更新';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! file_exists($path)) {
            $this->error("file not found: {$path}");

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            $this->error('invalid JSON: expected array');

            return self::FAILURE;
        }

        $source = $this->option('source');
        $this->info('インポート対象: '.count($rows).' 行 (source='.($source ?? 'all').')');

        $totalUpdated = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        DB::transaction(function () use ($rows, $source, &$totalUpdated, &$skipped, $bar) {
            foreach ($rows as $row) {
                $bar->advance();

                if (! isset($row['id']) && ! isset($row['message'])) {
                    $skipped++;

                    continue;
                }

                $messageText = $row['message'] ?? null;
                if ($messageText === null) {
                    $rep = Message::query()->find((int) $row['id']);
                    if ($rep === null) {
                        $skipped++;

                        continue;
                    }
                    $messageText = $rep->message;
                }

                $query = Message::query()->where('message', $messageText);
                if ($source !== null) {
                    $query->where('source', (string) $source);
                }

                $affected = $query->update([
                    'detected_language' => $row['detected_language'] ?? null,
                    'detected_language_label' => $row['detected_language_label'] ?? null,
                    'message_ja' => $row['message_ja'] ?? null,
                ]);
                $totalUpdated += $affected;
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("更新 row 合計: {$totalUpdated}, スキップ unique: {$skipped}");

        return self::SUCCESS;
    }
}
