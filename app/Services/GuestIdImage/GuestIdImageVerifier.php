<?php

namespace App\Services\GuestIdImage;

use App\Models\GuestRegistration;
use App\Services\AI\ClaudeClient;
use App\Services\AI\Exceptions\ClaudeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Claude Vision で身分証画像を検証するサービス。
 *
 * 二段階戦略でコストと精度を両立:
 *  1. まず Haiku で判定（1 枚 ~$0.003）
 *  2. Haiku の confidence < 0.7 または verdict = suspicious の場合のみ Sonnet で再判定
 *
 * さらに提出されたフォーム値（氏名・年齢・国籍）を AI に渡してクロスチェック
 * させ、書類記載内容との不一致を issues に追記する。有効期限が滞在期間を
 * カバーしていない場合も検出する。
 *
 * 結果は GuestRegistration::id_image_verdict / _ai_notes / _ai_extracted /
 * _verified_at に書き戻し、Filament UI で警告表示される。
 */
class GuestIdImageVerifier
{
    /** Haiku フォールバックしきい値。これ未満は Sonnet で再判定。 */
    private const HAIKU_CONFIDENCE_THRESHOLD = 0.7;

    private const HAIKU_MODEL = 'claude-haiku-4-5-20251001';

    private const SONNET_MODEL = 'claude-sonnet-4-6';

    public function __construct(private readonly ClaudeClient $client) {}

    public function verify(GuestRegistration $registration): void
    {
        if ($registration->id_image_path === null) {
            return;
        }

        $disk = config('guest_id_image.disk', 'r2');
        try {
            $raw = Storage::disk($disk)->get($registration->id_image_path);
        } catch (Throwable $e) {
            $this->markError($registration, 'Image not found on storage: '.$e->getMessage());

            return;
        }
        if ($raw === null) {
            $this->markError($registration, 'Image not found on storage.');

            return;
        }

        $mediaType = $this->guessMediaType($registration->id_image_path);
        $base64 = base64_encode($raw);
        $submittedContext = $this->buildSubmittedContext($registration);

        try {
            $haikuResult = $this->callModel(self::HAIKU_MODEL, $base64, $mediaType, $submittedContext);
        } catch (Throwable $e) {
            Log::warning('GuestIdImageVerifier: Haiku call failed', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
            $this->markError($registration, 'AI verification failed: '.$e->getMessage());

            return;
        }

        $finalResult = $haikuResult;

        $needsFallback = ($haikuResult['confidence'] ?? 1.0) < self::HAIKU_CONFIDENCE_THRESHOLD
            || ($haikuResult['verdict'] ?? null) === GuestRegistration::VERDICT_SUSPICIOUS;

        if ($needsFallback) {
            try {
                $finalResult = $this->callModel(self::SONNET_MODEL, $base64, $mediaType, $submittedContext);
                $finalResult['fallback_used'] = true;
            } catch (Throwable $e) {
                Log::warning('GuestIdImageVerifier: Sonnet fallback failed, keeping Haiku result', [
                    'registration_id' => $registration->id,
                    'error' => $e->getMessage(),
                ]);
                $finalResult['fallback_error'] = $e->getMessage();
            }
        }

        // AI 応答後にコード側でも念のためクロスチェック（AI の見落とし対策）。
        $finalResult = $this->applyPostChecks($finalResult, $registration);

        $registration->update([
            'id_image_verdict' => $finalResult['verdict'] ?? GuestRegistration::VERDICT_ERROR,
            'id_image_ai_notes' => $this->formatNotes($finalResult),
            'id_image_ai_extracted' => $finalResult,
            'id_image_verified_at' => now(),
        ]);
    }

    /**
     * プロンプトに埋め込む提出値サマリを組み立てる。
     */
    private function buildSubmittedContext(GuestRegistration $registration): string
    {
        $booking = $registration->booking;
        $arrival = $booking->arrival?->format('Y-m-d');
        $departure = $booking->departure?->format('Y-m-d');

        return sprintf(
            "Submitted by guest (compare against the ID):\n".
            "- name: %s\n".
            "- age: %s\n".
            "- nationality: %s\n".
            "Booking stay dates:\n".
            "- check-in: %s\n".
            '- check-out: %s',
            $registration->name,
            $registration->age !== null ? (string) $registration->age : 'unknown',
            $registration->nationality,
            $arrival ?? 'unknown',
            $departure ?? 'unknown',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function callModel(string $model, string $base64, string $mediaType, string $submittedContext): array
    {
        $system = <<<'PROMPT'
You are a strict ID document verifier for a Japanese lodging registration process. Analyze the attached image and the submitted guest info, then respond with ONLY a JSON object (no other text, no code fences, no commentary).

Format:
{
  "verdict": "ok" | "suspicious" | "invalid",
  "confidence": 0.0-1.0,
  "document_type": "passport" | "residence_card" | "driver_license" | "national_id" | "other" | "not_id",
  "extracted_name": string or null,
  "extracted_birth_date": "YYYY-MM-DD" or null,
  "extracted_nationality": string or null,
  "expiry_date": "YYYY-MM-DD" or null,
  "mismatches": [list of short human-readable strings in Japanese describing discrepancies between the submitted info and the ID; empty if none],
  "issues": [list of short human-readable issue strings in Japanese about image quality/authenticity; empty if none]
}

Verdict rules:
- "invalid": Not an ID at all (selfie without ID, landscape, screenshot of chat, blank, unrelated object).
- "suspicious": Is an ID but has quality/authenticity issues (blurry, cropped, dark, partially obscured, obvious photoshop, expired, fields not readable) OR has clear mismatches with the submitted info (different person, wrong nationality, expired before check-out).
- "ok": Clear government-issued ID with all key fields readable AND matches the submitted info.

Cross-check rules (put mismatches into "mismatches"):
- Compare extracted_name with submitted name. Allow romanization / kanji differences, order swaps, and minor typos. Only flag when they clearly refer to different people.
- Compare extracted_birth_date-derived age with submitted age. Allow ±1 year for birthday timing. Flag if the age deviates by 2+ years.
- Compare extracted_nationality with submitted nationality. "Japan" vs "日本" is a match. Flag only clear discrepancies.
- Check expiry_date against check-out date. Flag if the ID expires before check-out.

Confidence rules:
- 0.9-1.0: Very sure of the verdict.
- 0.6-0.9: Fairly sure but some uncertainty.
- 0.0-0.6: Low confidence; escalate to a stronger model.

If you cannot read the image at all, return verdict="invalid", confidence=0.9, issues=["画像を認識できません"], mismatches=[].

Output ONLY the JSON object.
PROMPT;

        $response = $this->client->createMessageWithImages(
            system: $system,
            userContent: [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mediaType,
                        'data' => $base64,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $submittedContext,
                ],
            ],
            maxTokens: 600,
            model: $model,
        );

        $parsed = $this->parseJsonResponse($response['text']);
        $parsed['_model'] = $response['model'];
        $parsed['_input_tokens'] = $response['input_tokens'];
        $parsed['_output_tokens'] = $response['output_tokens'];

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $text): array
    {
        $trimmed = trim($text);
        // まれに ```json ... ``` で返ることがあるので剥がす
        $trimmed = preg_replace('/^```(?:json)?\n?/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\n?```$/', '', $trimmed) ?? $trimmed;

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ClaudeException(
                message: 'GuestIdImageVerifier: could not parse JSON',
                context: ['raw' => $text, 'parse_error' => $e->getMessage()],
            );
        }

        if (! is_array($decoded)) {
            throw new ClaudeException(
                message: 'GuestIdImageVerifier: JSON is not an object',
                context: ['raw' => $text],
            );
        }

        return $decoded;
    }

    /**
     * AI 応答後のコード側チェック。有効期限 vs チェックアウトの決定的な照合など、
     * AI が省略しがちなケースを補完する。verdict の格上げも行う。
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function applyPostChecks(array $result, GuestRegistration $registration): array
    {
        $extraMismatches = [];

        $expiry = $result['expiry_date'] ?? null;
        $departure = $registration->booking->departure;
        if (is_string($expiry) && $departure !== null) {
            try {
                $expiryDate = Carbon::parse($expiry);
                if ($expiryDate->lt($departure)) {
                    $extraMismatches[] = sprintf(
                        '身分証の有効期限（%s）が滞在期間終了日（%s）より前です',
                        $expiryDate->format('Y-m-d'),
                        $departure->format('Y-m-d'),
                    );
                }
            } catch (Throwable) {
                // expiry_date が非常識な形式ならスキップ
            }
        }

        if ($extraMismatches !== []) {
            $existing = is_array($result['mismatches'] ?? null) ? $result['mismatches'] : [];
            $result['mismatches'] = array_values(array_merge($existing, $extraMismatches));

            // 決定的な期限切れは verdict を suspicious 以上に格上げ。
            if (($result['verdict'] ?? null) === GuestRegistration::VERDICT_OK) {
                $result['verdict'] = GuestRegistration::VERDICT_SUSPICIOUS;
            }
        }

        return $result;
    }

    /**
     * issues と mismatches を "\n" 区切りで平文化。Filament のフィールド表示用。
     *
     * @param  array<string, mixed>  $result
     */
    private function formatNotes(array $result): ?string
    {
        $lines = [];

        $issues = $result['issues'] ?? null;
        if (is_array($issues)) {
            foreach ($issues as $i) {
                $lines[] = '・'.(string) $i;
            }
        }

        $mismatches = $result['mismatches'] ?? null;
        if (is_array($mismatches)) {
            foreach ($mismatches as $m) {
                $lines[] = '⚠ '.(string) $m;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function markError(GuestRegistration $registration, string $message): void
    {
        $registration->update([
            'id_image_verdict' => GuestRegistration::VERDICT_ERROR,
            'id_image_ai_notes' => $message,
            'id_image_verified_at' => now(),
        ]);
    }

    private function guessMediaType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/webp',
        };
    }
}
