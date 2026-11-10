<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公開HP のロケール判定 middleware。判定順:
 *   1. ?lang=xx クエリパラメータ (LanguageSwitcher からの遷移用、Cookie に永続化)
 *   2. Cookie `locale`
 *   3. Accept-Language ヘッダの先頭 2 文字
 *   4. デフォルト (config('public_hp.default_locale'))
 *
 * 対応言語外は無視して次の判定に進む。
 */
class SetPublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->detectLocale($request);
        App::setLocale($locale);

        return $next($request);
    }

    private function detectLocale(Request $request): string
    {
        $supported = (array) config('public_hp.locales', ['ja']);
        $default = (string) config('public_hp.default_locale', 'ja');

        // 1. ?lang=xx でリクエスト時、Cookie にも保存
        $queryLocale = (string) $request->query('lang', '');
        if ($queryLocale !== '' && in_array($queryLocale, $supported, true)) {
            Cookie::queue('locale', $queryLocale, 60 * 24 * 365);

            return $queryLocale;
        }

        // 2. Cookie
        $cookieLocale = (string) $request->cookie('locale', '');
        if ($cookieLocale !== '' && in_array($cookieLocale, $supported, true)) {
            return $cookieLocale;
        }

        // 3. Accept-Language の主言語
        $accept = (string) $request->header('Accept-Language', '');
        if ($accept !== '') {
            $primary = strtolower(substr($accept, 0, 2));
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }
}
