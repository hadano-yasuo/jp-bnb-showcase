<?php

namespace App\Services\Beds24;

use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * beds24 API v2 クライアント（Phase 1a 骨子）
 *
 * - Refresh Token → Access Token 交換
 * - Access Token を Redis に 23h キャッシュ
 * - GET リクエストのレスポンス封筒を展開
 * - レート制限ヘッダーを Log に記録
 *
 * 詳細な API 仕様は docs/beds24-notes.md 参照
 */
class Beds24Client
{
    public function __construct(
        private readonly string $apiBase,
        private readonly string $refreshToken,
        private readonly string $accessTokenCacheKey,
        private readonly int $accessTokenTtlSeconds,
        private readonly int $httpTimeout,
    ) {}

    /**
     * GET リクエスト。レスポンス封筒を展開して返す。
     *
     * beds24 API v2 は OpenAPI 3 form-style explode=true 準拠のため、
     * 配列値は `key=v1&key=v2`（repeated key）で送る必要がある。
     * PHP/Laravel デフォルトの `key[0]=v1&key[1]=v2` は解釈されないので、
     * 配列値を含む場合は手動でクエリ文字列を組み立てて URL に埋め込む。
     *
     * @param  array<string, mixed>  $query
     * @return array{success: bool, type: string, count: int, pages: array<string, mixed>, data: array<int, array<string, mixed>>}
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->apiBase.$path;
        if ($query !== []) {
            $url .= (str_contains($path, '?') ? '&' : '?').$this->buildQueryString($query);
        }
        $response = $this->authorizedClient()->get($url);
        $this->recordRateLimit($response, 'GET '.$path);

        return $this->unwrapEnvelope($response, 'GET '.$path);
    }

    /**
     * OpenAPI 3 form-style explode=true 形式でクエリ文字列を組み立てる。
     * 配列値は同じキーを繰り返して並べる（例: `status=confirmed&status=cancelled`）。
     *
     * @param  array<string, mixed>  $query
     */
    private function buildQueryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
                }
            } elseif ($value !== null) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
        }

        return implode('&', $parts);
    }

    /**
     * ページネーション対応の GET。各ページを Generator で yield する。
     *
     * beds24 は 100件/page 固定・id DESC 順。`.pages.nextPageLink` を辿って
     * `.pages.nextPageExists: false` まで繰り返す。
     *
     * Newer-than-known 同期パターン（docs/beds24-notes.md 章4）では、caller が
     * 既知IDに到達した時点で foreach を break して早期終了する。
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array{success: bool, type: string, count: int, pages: array<string, mixed>, data: array<int, array<string, mixed>>}>
     */
    public function paginate(string $path, array $query = []): \Generator
    {
        $page = $this->get($path, $query);
        yield $page;

        while (($page['pages']['nextPageExists'] ?? false) === true) {
            $nextUrl = $page['pages']['nextPageLink'] ?? null;
            if (! is_string($nextUrl) || $nextUrl === '') {
                throw new Beds24Exception(
                    message: "nextPageExists=true だが nextPageLink が空: {$path}",
                    context: ['pages' => $page['pages']],
                );
            }

            $label = 'GET '.$path.' (next page)';
            $response = $this->authorizedClient()->get($nextUrl);
            $this->recordRateLimit($response, $label);
            $page = $this->unwrapEnvelope($response, $label);
            yield $page;
        }
    }

    /**
     * POST リクエスト。beds24 の POST は常に配列受け取り・配列返却。
     *
     * HTTP 2xx でも body 各要素の `success` を caller が確認する必要がある
     * （bulk POST の一部だけ失敗するパターンがある）。
     *
     * `/bookings/messages` の場合は各要素に `source: "host"` を含めること（beds24 側で必須）。
     *
     * @param  list<array<string, mixed>>  $items  1件でも `[{...}]` 形式で渡す
     * @return list<array<string, mixed>> 入力と同順の応答配列、要素毎に success/new/errors
     */
    public function post(string $path, array $items): array
    {
        if ($items === []) {
            throw new Beds24Exception(message: 'POST items must not be empty');
        }

        $response = $this->authorizedClient()
            ->asJson()
            ->post($this->apiBase.$path, $items);

        $this->recordRateLimit($response, 'POST '.$path);

        if ($response->failed()) {
            throw new Beds24Exception(
                message: "beds24 API POST failed: {$path}",
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! array_is_list($data)) {
            throw new Beds24Exception(
                message: "Unexpected POST response format: {$path}",
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        return $data;
    }

    /**
     * 現在有効な Access Token を返す（キャッシュ優先、無ければ Refresh Token で発行）。
     */
    public function getAccessToken(): string
    {
        $cached = Cache::get($this->accessTokenCacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->refreshAccessToken();
        Cache::put($this->accessTokenCacheKey, $token, $this->accessTokenTtlSeconds);

        return $token;
    }

    /**
     * 明示的に Access Token を再取得（既存キャッシュを破棄）。
     */
    public function refreshAccessToken(): string
    {
        $response = Http::withHeaders([
            'refreshToken' => $this->refreshToken,
            'Accept' => 'application/json',
        ])
            ->timeout($this->httpTimeout)
            ->get("{$this->apiBase}/authentication/token");

        if ($response->failed()) {
            throw new Beds24Exception(
                message: 'Failed to refresh access token',
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new Beds24Exception(
                message: 'Access token missing in refresh response',
                context: ['response' => $response->json()],
            );
        }

        Cache::put($this->accessTokenCacheKey, $token, $this->accessTokenTtlSeconds);
        Log::info('beds24 access token refreshed', ['cache_ttl' => $this->accessTokenTtlSeconds]);

        return $token;
    }

    /**
     * キャッシュされた Access Token を強制破棄（テスト用）
     */
    public function forgetAccessToken(): void
    {
        Cache::forget($this->accessTokenCacheKey);
    }

    private function authorizedClient(): PendingRequest
    {
        return Http::withHeaders([
            'token' => $this->getAccessToken(),
            'Accept' => 'application/json',
        ])->timeout($this->httpTimeout);
    }

    /**
     * beds24 の共通レスポンス封筒を展開する。
     * 期待形式: `{success, type, count, pages, data}`
     *
     * @return array{success: bool, type: string, count: int, pages: array<string, mixed>, data: array<int, array<string, mixed>>}
     */
    private function unwrapEnvelope(Response $response, string $requestLabel): array
    {
        if ($response->failed()) {
            throw new Beds24Exception(
                message: "beds24 API request failed: {$requestLabel}",
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new Beds24Exception(
                message: "Unexpected response format: {$requestLabel}",
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        if (($data['success'] ?? false) !== true) {
            throw new Beds24Exception(
                message: "beds24 API returned error: {$requestLabel}",
                httpStatus: $response->status(),
                context: ['response' => $data],
            );
        }

        return $data;
    }

    private function recordRateLimit(Response $response, string $requestLabel): void
    {
        $cost = $response->header('X-Request-Cost');
        $remaining = $response->header('X-Five-Min-Limit-Remaining');
        $resetsIn = $response->header('X-Five-Min-Limit-Resets-In');

        if ($cost === '' && $remaining === '' && $resetsIn === '') {
            return;
        }

        Log::debug('beds24 rate limit', [
            'request' => $requestLabel,
            'cost' => $cost,
            'remaining' => $remaining,
            'resets_in_sec' => $resetsIn,
        ]);
    }
}
