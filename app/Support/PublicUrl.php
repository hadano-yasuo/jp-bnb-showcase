<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * ゲストに送るメール本文などで使う、公開HPドメイン固定の URL ジェネレータ。
 *
 * URL::route() はコンテキスト依存 (HTTP リクエスト host or APP_URL = admin.example.com)
 * なので、queue job や artisan 経由で生成すると管理ドメインが混じってしまう。
 * 本クラスは PUBLIC_HP_DOMAIN が設定されていれば強制的にそのホストに差し替える。
 */
class PublicUrl
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function route(string $name, array $params = []): string
    {
        $url = URL::route($name, $params);
        $publicDomain = (string) config('public_hp.domain', '');
        if ($publicDomain === '') {
            return $url;
        }

        $currentHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($currentHost) || $currentHost === $publicDomain) {
            return $url;
        }

        return (string) preg_replace(
            '#://'.preg_quote($currentHost, '#').'#',
            '://'.$publicDomain,
            $url,
            1,
        );
    }
}
