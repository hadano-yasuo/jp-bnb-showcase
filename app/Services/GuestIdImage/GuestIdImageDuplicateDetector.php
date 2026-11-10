<?php

namespace App\Services\GuestIdImage;

use App\Models\GuestRegistration;
use Illuminate\Support\Collection;

/**
 * ゲスト身分証画像の重複検出。
 *
 * 2 段の照合ロジック:
 *  1. SHA-256（原本ファイル基準）の完全一致 → バイト同じファイルの流用検知
 *  2. AI 抽出氏名（id_image_ai_extracted->extracted_name）の一致 →
 *     同一人物の別ショット / 同じ書類の別撮影を検知
 *
 * 検出は Filament の詳細ページで on-demand に呼ばれ、警告バッジ表示に使う。
 * ゲスト側の送信は止めない（ホストの目視レビュー前提）。
 */
class GuestIdImageDuplicateDetector
{
    /**
     * 検出結果を返す。空配列なら重複なし。
     *
     * @return list<array{type: 'exact'|'name_match', registration: GuestRegistration}>
     */
    public function detect(GuestRegistration $registration): array
    {
        $sha256 = $registration->id_image_sha256;
        $extractedName = $this->normalizedExtractedName($registration);

        if ($sha256 === null && $extractedName === null) {
            return [];
        }

        // 過去 180 日以内の予約に紐づくレコード（インデックス使用）に絞る。
        // 実運用では 3 年保管しているが、日常的な使い回し検知にはこの範囲で十分。
        $candidates = GuestRegistration::query()
            ->where('id', '!=', $registration->id)
            ->whereNotNull('id_image_path')
            ->with('booking')
            ->whereHas('booking', fn ($q) => $q->whereDate('arrival', '>=', now()->subDays(180)))
            ->get();

        $results = [];
        $seenIds = [];

        foreach ($candidates as $c) {
            /** @var GuestRegistration $c */
            if ($sha256 !== null && $c->id_image_sha256 === $sha256) {
                $results[] = ['type' => 'exact', 'registration' => $c];
                $seenIds[] = $c->id;

                continue;
            }

            $candidateName = $this->normalizedExtractedName($c);
            if ($extractedName !== null && $candidateName !== null && $extractedName === $candidateName) {
                if (! in_array($c->id, $seenIds, true)) {
                    $results[] = ['type' => 'name_match', 'registration' => $c];
                    $seenIds[] = $c->id;
                }
            }
        }

        return $results;
    }

    /**
     * 複数のレコードをまとめて検出する（Filament 詳細ページ向け・N+1 回避）。
     *
     * @param  Collection<int, GuestRegistration>  $registrations
     * @return array<int, list<array{type: 'exact'|'name_match', registration: GuestRegistration}>>
     */
    public function detectBatch(Collection $registrations): array
    {
        $result = [];
        foreach ($registrations as $r) {
            $result[$r->id] = $this->detect($r);
        }

        return $result;
    }

    /**
     * AI 抽出氏名を正規化（大文字小文字・空白除去）して比較用に返す。
     */
    private function normalizedExtractedName(GuestRegistration $registration): ?string
    {
        $extracted = $registration->id_image_ai_extracted;
        if (! is_array($extracted)) {
            return null;
        }

        $name = $extracted['extracted_name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }

        $normalized = mb_strtolower((string) preg_replace('/\s+/', '', $name));

        return $normalized === '' ? null : $normalized;
    }
}
