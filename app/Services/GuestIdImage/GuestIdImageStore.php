<?php

namespace App\Services\GuestIdImage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * ゲスト身分証画像を Cloudflare R2 に保存する。
 *
 * 二段階の画質戦略:
 *  - アップロード時: 長辺 2400px, WebP 品質 90 で「原本」を保存（顔・ID番号鮮明）
 *  - チェックアウト+7日後の Job: 長辺 1600px, 品質 70 に再圧縮して原本を上書き
 *    → 旅館業法の 3 年保管に耐える顔照合可能な最低画質を維持しつつ容量削減
 *
 * PDF や HEIC など非対応形式が来た場合は Intervention 側で例外が上がる → コントローラで捕捉する。
 */
class GuestIdImageStore
{
    public const UPLOAD_MAX_EDGE = 2400;

    public const UPLOAD_QUALITY = 90;

    public const COMPRESSED_MAX_EDGE = 1600;

    public const COMPRESSED_QUALITY = 70;

    public function __construct(private readonly ImageManager $manager = new ImageManager(new Driver)) {}

    /**
     * 画像を R2 に保存し、path と SHA-256 ハッシュ（原本ファイル基準）を返す。
     *
     * SHA-256 はリサイズ・再エンコード前の原本を対象にすることで、
     * 「同じソース画像を別ゲスト分として使い回した」ケースを検知できる。
     *
     * @return array{path: string, sha256: string}
     */
    public function storeOriginal(UploadedFile $file, int $bookingId, int $guestNumber): array
    {
        $sha256 = (string) hash_file('sha256', $file->getRealPath());

        $image = $this->manager
            ->decode($file->getRealPath())
            ->scaleDown(width: self::UPLOAD_MAX_EDGE, height: self::UPLOAD_MAX_EDGE);

        $webp = (string) $image->encode(new WebpEncoder(quality: self::UPLOAD_QUALITY));

        $path = sprintf(
            'guest-ids/%d/%d-%s.webp',
            $bookingId,
            $guestNumber,
            Str::random(12),
        );

        Storage::disk($this->disk())->put($path, $webp, 'private');

        return ['path' => $path, 'sha256' => $sha256];
    }

    public function compress(string $path): void
    {
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($path)) {
            return;
        }

        $original = $disk->get($path);
        if ($original === null) {
            return;
        }

        $image = $this->manager
            ->decode($original)
            ->scaleDown(width: self::COMPRESSED_MAX_EDGE, height: self::COMPRESSED_MAX_EDGE);

        $webp = (string) $image->encode(new WebpEncoder(quality: self::COMPRESSED_QUALITY));

        $disk->put($path, $webp, 'private');
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }

    public function temporaryUrl(string $path, int $minutes = 30): string
    {
        return Storage::disk($this->disk())->temporaryUrl($path, now()->addMinutes($minutes));
    }

    private function disk(): string
    {
        return config('guest_id_image.disk', 'r2');
    }
}
