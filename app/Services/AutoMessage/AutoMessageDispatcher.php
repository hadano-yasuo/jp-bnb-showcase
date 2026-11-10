<?php

namespace App\Services\AutoMessage;

use App\Mail\AutoMessageMail;
use App\Models\AutoMessageRule;
use App\Models\AutoMessageSend;
use App\Models\Booking;
use App\Models\Message;
use App\Services\AI\TranslationService;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * 発火時刻を過ぎた pending 送信を処理する。
 *
 * 配信方針:
 *   - 自社サイト直接予約 (api_source=direct) → AutoMessageMail でメール配信 (STATUS_SENT)。
 *     ゲストが OTA のメッセンジャーを持たないため、メール + /chat/{token} が唯一の
 *     ホストとの連絡経路。
 *   - OTA ゲスト (Airbnb / Booking.com) → 実送信未実装、dry_run のまま。
 *     beds24 messaging 経由の送信は別フェーズで対応予定 (memory: feedback_execution_off_by_default)。
 */
class AutoMessageDispatcher
{
    /** OTA 向けはまだ dry_run (beds24 送信未実装)。 */
    public const SKIP_SENDING_DISABLED = 'sending_not_implemented';

    /** api_source がこのいずれかなら直接予約扱いでメール配信する。
     * beds24 は POST 時に apiSource='direct' を受け、同期で返す値は 'Direct' に書き換わる。 */
    private const DIRECT_API_SOURCES = ['direct', 'direct'];

    public function __construct(
        private readonly ChannelMatcher $channelMatcher,
        private readonly BodyRenderer $renderer,
        private readonly EnabledFromGuard $enabledFromGuard,
        private readonly TranslationService $translator,
        private readonly NameLanguageGuesser $nameGuesser,
    ) {}

    /**
     * @return array{processed: int, dry_run: int, sent: int, skipped: int}
     */
    public function dispatchDue(?\DateTimeInterface $now = null): array
    {
        $now = $now ?? now();
        $processed = 0;
        $dryRun = 0;
        $sent = 0;
        $skipped = 0;

        AutoMessageSend::query()
            ->where('status', AutoMessageSend::STATUS_PENDING)
            ->where('scheduled_at', '<=', $now)
            ->with(['rule', 'booking.listing', 'booking.guestChannel'])
            ->orderBy('scheduled_at')
            ->chunkById(200, function ($sends) use (&$processed, &$dryRun, &$sent, &$skipped) {
                foreach ($sends as $send) {
                    $processed++;
                    $result = $this->processOne($send);
                    match ($result) {
                        'sent' => $sent++,
                        'dry_run' => $dryRun++,
                        default => $skipped++,
                    };
                }
            });

        return ['processed' => $processed, 'dry_run' => $dryRun, 'sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * @return 'sent'|'dry_run'|'skipped'
     */
    private function processOne(AutoMessageSend $send): string
    {
        // cascadeOnDelete で auto_message_sends は rule / booking 削除時に消える。
        // よって send が存在する限り rule / booking は非 null。
        $rule = $send->rule;
        $booking = $send->booking;

        if ($this->enabledFromGuard->blocks($rule, $booking)) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_BEFORE_ENABLED_FROM,
            ]);

            return 'skipped';
        }

        // キャンセル時トリガーは例外的にキャンセル済み予約でも送る（キャンセル理由確認等）。
        if ($booking->status === Booking::STATUS_CANCELLED
            && $rule->trigger_event !== AutoMessageRule::EVENT_CANCELLED
        ) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_CANCELLED,
            ]);

            return 'skipped';
        }

        if ($booking->auto_message_opt_out) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_OPTED_OUT,
            ]);

            return 'skipped';
        }

        // 「滞在中のみ送信」オプションが ON の場合、departure（当日終わり）を
        // 過ぎたスケジュールは skip。1 泊予約に checkin+2日 を送らない用途。
        if ($rule->only_when_staying
            && $booking->departure !== null
            && $send->scheduled_at->gt($booking->departure->copy()->endOfDay())
        ) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_AFTER_DEPARTURE,
            ]);

            return 'skipped';
        }

        if (! $this->channelMatcher->matches($rule->channel_scope, $booking->api_source)) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_CHANNEL_MISMATCH,
            ]);

            return 'skipped';
        }

        // rule->condition が指定されていれば dispatch 時に評価する。
        // registration_incomplete: ゲスト情報登録が完了している予約には送らない
        // (リマインドなので既に埋まっているなら不要)。
        if ($rule->condition === AutoMessageRule::CONDITION_REGISTRATION_INCOMPLETE
            && ! $booking->isRegistrationIncomplete()
        ) {
            $send->update([
                'status' => AutoMessageSend::STATUS_SKIPPED,
                'skip_reason' => AutoMessageSend::SKIP_CONDITION_UNMET,
            ]);

            return 'skipped';
        }

        [$targetLang, $targetLangLabel] = $this->resolveTargetLanguage($rule, $booking);

        try {
            $rendered = $this->renderer->render($rule->body, $booking, $targetLang);
        } catch (Throwable $e) {
            Log::warning('auto_message render failed', [
                'send_id' => $send->id,
                'rule_id' => $rule->id,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            $send->update([
                'status' => AutoMessageSend::STATUS_FAILED,
                'error' => 'render: '.$e->getMessage(),
            ]);

            return 'skipped';
        }

        // ja 以外なら定型文フローと同じく ja→target 翻訳を掛ける（本文は日本語で書く前提）。
        $translated = null;
        if ($targetLang !== 'ja') {
            try {
                $translated = $this->translator->translateFromJa(
                    japaneseText: $rendered,
                    targetLangCode: $targetLang,
                    targetLangLabel: $targetLangLabel,
                );
            } catch (Throwable $e) {
                Log::warning('auto_message translate failed', [
                    'send_id' => $send->id,
                    'rule_id' => $rule->id,
                    'booking_id' => $booking->id,
                    'target_lang' => $targetLang,
                    'error' => $e->getMessage(),
                ]);
                $send->update([
                    'status' => AutoMessageSend::STATUS_FAILED,
                    'rendered_body' => $rendered,
                    'error' => 'translate: '.$e->getMessage(),
                ]);

                return 'skipped';
            }
        }

        // 自社サイト直接予約はメール配信、OTA は未実装 (dry_run)。
        if ($rule->is_enabled && $this->isDirectEmailChannel($booking)) {
            return $this->sendDirectBookingEmail(
                send: $send,
                booking: $booking,
                rule: $rule,
                rendered: $rendered,
                translated: $translated,
                targetLang: $targetLang,
                targetLangLabel: $targetLangLabel,
            );
        }

        // 実送信は未実装 (OTA)。is_enabled=true でも dry_run と同じ扱いで記録し、
        // 別途「実送信フェーズ」で処理する（切替時に skip_reason で識別可能）。
        $send->update([
            'status' => AutoMessageSend::STATUS_DRY_RUN,
            'rendered_body' => $rendered,
            'rendered_body_translated' => $translated,
            'translated_to_lang' => $translated !== null ? $targetLang : null,
            'skip_reason' => $rule->is_enabled ? self::SKIP_SENDING_DISABLED : null,
        ]);

        return 'dry_run';
    }

    private function isDirectEmailChannel(Booking $booking): bool
    {
        $source = strtolower((string) $booking->api_source);

        return in_array($source, self::DIRECT_API_SOURCES, true);
    }

    /**
     * @return 'sent'|'skipped'
     */
    private function sendDirectBookingEmail(
        AutoMessageSend $send,
        Booking $booking,
        AutoMessageRule $rule,
        string $rendered,
        ?string $translated,
        string $targetLang,
        ?string $targetLangLabel,
    ): string {
        $toEmail = (string) ($booking->email ?? '');
        if ($toEmail === '') {
            $send->update([
                'status' => AutoMessageSend::STATUS_FAILED,
                'rendered_body' => $rendered,
                'rendered_body_translated' => $translated,
                'translated_to_lang' => $translated !== null ? $targetLang : null,
                'error' => 'no guest email on booking',
            ]);

            return 'skipped';
        }

        $body = $translated ?? $rendered;
        $chatUrl = $this->chatUrlFor($booking);

        try {
            Mail::to($toEmail)->send(new AutoMessageMail(
                subjectLine: $this->subjectLineFor($rule, $targetLang),
                bodyRendered: $body,
                guestLocale: $targetLang,
                chatUrl: $chatUrl,
            ));
            // admin タイムラインに表示するため Message に記録 (beds24 side には POST しない)
            Message::create([
                'beds24_message_id' => null,
                'beds24_booking_id' => $booking->beds24_booking_id,
                'beds24_property_id' => $booking->beds24_property_id,
                'beds24_room_id' => $booking->beds24_room_id,
                'source' => Message::SOURCE_HOST,
                'message' => $body,
                'message_ja' => $targetLang !== 'ja' ? $rendered : null,
                'detected_language' => $targetLang,
                'detected_language_label' => $targetLangLabel,
                'is_read' => true,
                'sent_at' => now(),
                'fetched_at' => now(),
                'emailed_to' => $toEmail,
                'emailed_at' => now(),
                'raw_payload' => [],
            ]);
        } catch (Throwable $e) {
            Log::error('auto_message email send failed', [
                'send_id' => $send->id,
                'rule_id' => $rule->id,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            $send->update([
                'status' => AutoMessageSend::STATUS_FAILED,
                'rendered_body' => $rendered,
                'rendered_body_translated' => $translated,
                'translated_to_lang' => $translated !== null ? $targetLang : null,
                'error' => 'mail: '.mb_substr($e->getMessage(), 0, 500),
            ]);

            return 'skipped';
        }

        $send->update([
            'status' => AutoMessageSend::STATUS_SENT,
            'rendered_body' => $rendered,
            'rendered_body_translated' => $translated,
            'translated_to_lang' => $translated !== null ? $targetLang : null,
            'sent_at' => now(),
        ]);

        return 'sent';
    }

    /**
     * 予約に紐付く GuestChannel があればその /chat/{token} URL を返す。
     * ゲスト宛メールに「チャットを開く」ボタンを常時出せるようにする。
     */
    private function chatUrlFor(Booking $booking): ?string
    {
        $channel = $booking->guestChannel;
        if ($channel === null || ! Route::has('chat.show')) {
            return null;
        }

        return PublicUrl::route('chat.show', ['token' => $channel->token]);
    }

    private function subjectLineFor(AutoMessageRule $rule, string $locale): string
    {
        $brand = 'Sample Stay';
        $name = trim((string) $rule->name);
        $prefix = match ($locale) {
            'en' => 'A message from',
            'es' => 'Mensaje de',
            'zh' => '来自',
            'ko' => '안내',
            default => 'ご案内',
        };

        return match ($locale) {
            'ja' => "{$brand} からの{$prefix}".($name !== '' ? " - {$name}" : ''),
            'ko' => "{$brand} {$prefix}".($name !== '' ? " - {$name}" : ''),
            'zh' => "{$prefix}{$brand}".($name !== '' ? " - {$name}" : ''),
            default => "{$prefix} {$brand}".($name !== '' ? " - {$name}" : ''),
        };
    }

    /**
     * 翻訳先言語を決定する:
     *   1. rule.locale が明示指定されていればそれを使う
     *   2. 予約の最新ゲストメッセージの detected_language を使う
     *      （画像添付など実質空のメッセージは除外して信頼できる直近を採る）
     *   3. ゲスト姓名から NameLanguageGuesser で推測する
     *      （booking_created 時点はメッセージ 0 件なのでここが本命。判定不能は en）
     *
     * @return array{0: string, 1: string|null} [langCode, langLabel]
     */
    private function resolveTargetLanguage(AutoMessageRule $rule, Booking $booking): array
    {
        if ($rule->locale !== null && $rule->locale !== '') {
            return [$rule->locale, null];
        }

        // hasDetectableText でフィルタしてから最新を採る:
        // Airbnb 画像添付メッセージなど本文が実質空のものは detected_language='en' に
        // 固定されがちで（LanguageDetector の "短すぎ→en" フォールバック）、それを
        // 最新扱いすると翻訳先が誤爆する。SQL では判定できないので 20 件フェッチして
        // PHP 側で filter。
        $latest = $booking->messages()
            ->where('source', Message::SOURCE_GUEST)
            ->whereNotNull('detected_language')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->first(fn (Message $m) => $m->hasDetectableText());

        if ($latest !== null) {
            return [$latest->detected_language, $latest->detected_language_label];
        }

        return $this->nameGuesser->guess($booking->last_name, $booking->first_name);
    }
}
