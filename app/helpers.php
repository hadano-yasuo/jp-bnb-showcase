<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('image_url')) {
    /**
     * 公開HP 画像の URL を生成する。ソースは config('public_hp.image_source') で切替:
     *   'local' → /images/<path>            (Laravel の public/images/、git 管理外)
     *   'r2'    → R2 public bucket URL       (本番、Task #19 で移行)
     *
     * R2 未設定 (bucket / url 空) の場合は自動で local フォールバック。
     * ローカル参照時はキャッシュバスターを付けない（静的配信で不要）。
     */
    function image_url(string $path, ?string $version = null): string
    {
        $path = ltrim($path, '/');
        $source = (string) config('public_hp.image_source', 'local');

        if ($source === 'r2') {
            $publicUrl = (string) config('filesystems.disks.public_hp.url', '');
            $bucket = (string) config('filesystems.disks.public_hp.bucket', '');
            if ($publicUrl !== '' && $bucket !== '') {
                $version ??= (string) config('public_hp.image_version', 'v1');
                $url = Storage::disk('public_hp')->url($path);

                return $version === '' ? $url : $url.'?v='.$version;
            }
            // R2 未設定 → local フォールバック
        }

        return '/images/'.$path;
    }
}
