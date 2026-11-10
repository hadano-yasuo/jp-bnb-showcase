<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公開HP フォーム用 honeypot + time trap。
 *
 * - honeypot 隠しフィールド `website` に何か入っていたら bot として reject
 * - `form_ts` (Crypt::encryptString された unix time) をフォーム描画時に埋め、
 *   送信時に経過秒を検査。閾値未満は bot として reject。
 *
 * どちらも 422 で silent reject し、詳細な理由はレスポンスに含めない
 * (bot に対策のヒントを与えないため)。
 */
class SpamGuard
{
    private const MIN_ELAPSED_SECONDS = 2;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('website')) {
            abort(422);
        }

        $ts = $request->input('form_ts');

        if (! is_string($ts) || $ts === '') {
            abort(422);
        }

        try {
            $formTime = (int) Crypt::decryptString($ts);
        } catch (DecryptException) {
            abort(422);
        }

        if (time() - $formTime < self::MIN_ELAPSED_SECONDS) {
            abort(422);
        }

        return $next($request);
    }
}
