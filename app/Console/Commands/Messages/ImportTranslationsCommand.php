<?php

namespace App\Console\Commands\Messages;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * JSON ファイル（配列: {id, detected_language, detected_language_label, message_ja}）から
 * messages テーブルに translation を一括反映する。
 *
 * Claude Code 経由で翻訳した結果を staging/本番 DB に流し込む用途。
 *
 * 使い方:
 *   scp translations.json forge@server:/tmp/tr.json
 *   php artisan messages:import-translations /tmp/tr.json
 */
class ImportTranslationsCommand extends Command
{
    protected $signature = 'messages:import-translations {path : JSON ファイルパス}';

    protected $description = 'JSON から messages.message_ja / detected_language を一括更新';

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

        $this->info('インポート対象: '.count($rows).' 行');

        $updated = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        DB::transaction(function () use ($rows, &$updated, &$skipped, $bar) {
            foreach ($rows as $row) {
                if (! isset($row['id'])) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }
                $affected = Message::query()
                    ->where('id', (int) $row['id'])
                    ->update([
                        'detected_language' => $row['detected_language'] ?? null,
                        'detected_language_label' => $row['detected_language_label'] ?? null,
                        'message_ja' => $row['message_ja'] ?? null,
                    ]);
                if ($affected > 0) {
                    $updated++;
                } else {
                    $skipped++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("更新: {$updated} 行, スキップ: {$skipped} 行");

        return self::SUCCESS;
    }
}
