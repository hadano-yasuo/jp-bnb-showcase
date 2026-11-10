<?php

namespace App\Services\Extractors;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Airbnb 公開ページ (https://www.airbnb.jp/rooms/{id}) から
 * リスティング情報を抽出して Markdown に整形する。
 *
 * HTML 内の <script data-injector> と <script type="application/json"> に
 * 埋め込まれた GraphQL レスポンス（DemandStayListingDescription 等）を
 * 正規表現で抜き出す。JS 実行不要。
 */
class AirbnbListingExtractor
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

    /**
     * URL から Markdown 生成。
     *
     * @throws RuntimeException 取得失敗 or 抽出結果が空の場合
     */
    public function extract(string $url): string
    {
        $this->assertAirbnbUrl($url);

        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Accept-Language' => 'ja-JP,ja;q=0.9,en;q=0.8',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ])->timeout(15)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Airbnb ページの取得に失敗 (HTTP {$response->status()})");
        }

        $html = $response->body();
        if (strlen($html) < 50_000) {
            throw new RuntimeException('レスポンスが小さすぎます。URL が無効か bot 検知の可能性');
        }

        // data-injector script (GraphQL / Apollo cache)
        preg_match('/<script[^>]*data-injector[^>]*>(.*?)<\/script>/s', $html, $mInjector);
        $injected = $mInjector[1] ?? '';
        $injected = html_entity_decode($injected, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // application/json scripts (amenities などが入っている)
        preg_match_all('/<script[^>]*type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $mJsons);
        $allJson = implode("\n", $mJsons[1]);
        $allJson = html_entity_decode($allJson, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $summary = $this->firstMatch(
            '/"summary":\{"__typename":"UGCText","localizedString":"([^"]+)"/',
            $injected
        );
        $bedCount = $this->firstMatch('/"bedCount":([0-9]+)/', $injected);

        // amenities（icon 付き title で判別、重複除外）
        preg_match_all('/"title":"([^"]+)","icon":"[^"]+"/', $allJson, $mAmen);
        $amenities = [];
        foreach ($mAmen[1] as $a) {
            $clean = str_replace("\u{2060}", '', $a);   // word joiner除去
            if ($clean !== '' && mb_strlen($clean) < 60 && ! in_array($clean, $amenities, true)) {
                $amenities[] = $clean;
            }
        }

        if ($summary === null && $amenities === []) {
            throw new RuntimeException('リスティング情報の抽出に失敗（HTML 構造が変わった可能性）');
        }

        return $this->buildMarkdown([
            'summary' => $summary,
            'bedCount' => $bedCount,
            'amenities' => $amenities,
        ]);
    }

    private function assertAirbnbUrl(string $url): void
    {
        if (! preg_match('#^https?://(www\.)?airbnb\.(jp|com|[a-z.]+)/rooms/\d+#i', $url)) {
            throw new RuntimeException('Airbnb の rooms URL を指定してください（例: https://www.airbnb.jp/rooms/12345）');
        }
    }

    private function firstMatch(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $m)) {
            return $this->unescapeJsonString($m[1]);
        }

        return null;
    }

    private function unescapeJsonString(string $s): string
    {
        // JSON エスケープ (\n, \/, \") をデコード
        return str_replace(['\\n', '\\/', '\\"'], ["\n", '/', '"'], $s);
    }

    /**
     * @param  array{summary:?string, bedCount:?string, amenities:array<int,string>}  $data
     */
    private function buildMarkdown(array $data): string
    {
        $md = '';

        if ($data['summary'] !== null) {
            $md .= "## 概要\n\n".trim($data['summary'])."\n\n";
        }

        if ($data['bedCount'] !== null) {
            $md .= "## 部屋情報\n\n- ベッド数: {$data['bedCount']}\n\n";
        }

        if ($data['amenities'] !== []) {
            $md .= "## アメニティ・設備\n\n";
            foreach ($data['amenities'] as $a) {
                $md .= "- {$a}\n";
            }
        }

        return rtrim($md)."\n";
    }
}
