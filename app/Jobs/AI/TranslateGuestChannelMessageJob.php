<?php

namespace App\Jobs\AI;

use App\Models\GuestChannelMessage;
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
 * ゲストチャネル (自社HP /contact / /chat) のメッセージを
 * 言語検出 → 日本語翻訳する非同期 Job。beds24 側の
 * TranslateGuestMessageJob と同じ挙動をゲストチャネル用モデルで実行する。
 *
 * - guest / host いずれの発言も対象。system はスキップ
 * - detected_language が ja なら翻訳スキップ
 * - 既に message_ja がセット済みならスキップ（冪等）
 */
class TranslateGuestChannelMessageJob implements ShouldQueue
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
        $message = GuestChannelMessage::find($this->messageId);
        if ($message === null) {
            return;
        }
        if ($message->source === GuestChannelMessage::SOURCE_SYSTEM) {
            return;
        }
        if ($message->message_ja !== null) {
            return;
        }
        if (trim($message->message) === '') {
            return;
        }
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
            Log::warning('translate guest channel message failed', [
                'guest_channel_message_id' => $this->messageId,
                'source' => $message->source,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
