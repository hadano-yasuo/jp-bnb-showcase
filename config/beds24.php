<?php

return [
    /**
     * beds24 API v2 のベース URL
     */
    'api_base' => env('BEDS24_API_BASE', 'https://beds24.com/api/v2'),

    /**
     * Refresh Token（30日未使用で失効、Invite Code 交換で取得）
     * 実環境では .env に配置、暗号化はDB保存時に App\Models\Beds24Credential で行う
     */
    'refresh_token' => env('BEDS24_REFRESH_TOKEN'),

    /**
     * Access Token のキャッシュ設定
     * beds24 の Access Token は 24h 有効なので、余裕を持って 23h キャッシュ
     */
    'access_token_cache_key' => 'beds24:access_token',
    'access_token_ttl_seconds' => 23 * 60 * 60,

    /**
     * HTTP タイムアウト（秒）
     */
    'http_timeout' => 30,

    /**
     * Booking Webhook 認証用の共有シークレット。
     * beds24 dashboard の Custom Header で `X-Webhook-Secret: <この値>` を設定する。
     * 空の場合は認証失敗扱い（=秘密の設定漏れで誰でも叩けるようにしない）。
     */
    'webhook_secret' => env('BEDS24_WEBHOOK_SECRET'),

];
