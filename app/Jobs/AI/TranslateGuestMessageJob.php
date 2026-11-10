<?php

namespace App\Jobs\AI;

use App\Models\Message;
use App\Services\AI\LanguageDetector;
use App\Services\AI\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * メッセージを言語検出→日本語翻訳する非同期 Job（guest / host 両対応）。
 *
 * - SyncMessagesJob が新規メッセージを作成した後にキューへ投入
 * - Command からの backfill でも同じ Job を利用
 * - detected_language が ja なら翻訳スキップ
 * - 既に message_ja がセット済みならスキップ（冪等）
 * - system メッセージは対象外（内容が定型的で翻訳する意味が薄い）
 *
 * クラス名は歴史的経緯で TranslateGuestMessageJob のままだが host も扱う。
 */
class TranslateGuestMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(LanguageDetector $detector, TranslationService $translator): void
    {
        $message = Message::find($this->messageId);
        if ($message === null) {
            return;
        }
        if ($message->source === Message::SOURCE_SYSTEM) {
            return;
        }
        if ($message->message_ja !== null) {
            return;
        }
        if (trim($message->message) === '') {
            return;
        }

        // 画像添付など実テキストが無いメッセージは言語判定不能。無理に judge すると
        // en に固定されて後続の翻訳先が誤爆するので detected_language を null のまま残す。
        if (! $message->hasDetectableText()) {
            return;
        }

        try {
            if ($message->detected_language === null) {
                $detector->detect($message);
                $message->refresh();
            }

            $translator->translateGuestMessageToJa($message);
        } catch (Throwable $e) {
            Log::warning('translate message failed', [
                'message_id' => $this->messageId,
                'source' => $message->source,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
