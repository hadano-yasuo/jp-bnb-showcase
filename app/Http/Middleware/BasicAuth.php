<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * env で指定した 1 組の credentials で HTTP Basic 認証を要求するシンプル middleware。
 *
 * .env:
 *   BASIC_AUTH_USER=admin
 *   BASIC_AUTH_PASS=xxxxxxxxxx
 *
 * どちらかが未設定なら middleware は素通し (fail open) — ローカル dev で
 * 未設定でも壊れないようにするため。本番でルートを保護したい時は必ず両方を
 * 設定すること。
 */
class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = (string) config('app.basic_auth.user', '');
        $expectedPass = (string) config('app.basic_auth.pass', '');

        if ($expectedUser === '' || $expectedPass === '') {
            // credentials 未設定なら素通し (fail open)
            return $next($request);
        }

        $providedUser = (string) $request->getUser();
        $providedPass = (string) $request->getPassword();

        if (! hash_equals($expectedUser, $providedUser) || ! hash_equals($expectedPass, $providedPass)) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Protected"',
            ]);
        }

        return $next($request);
    }
}
