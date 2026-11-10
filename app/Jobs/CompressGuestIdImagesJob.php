<?php

namespace App\Jobs;

use App\Models\GuestRegistration;
use App\Services\GuestIdImage\GuestIdImageStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * チェックアウト+7 日を過ぎたゲスト身分証画像を軽量化する日次バッチ。
 *
 * 原本（長辺 2400px, WebP 品質 90）→ 圧縮版（長辺 1600px, 品質 70）に R2 上で再エンコード。
 * 顔・ID 番号が判読できる最低画質を維持しつつ、旅館業法の 3 年保管を安価に運用する。
 *
 * トークンの有効期限は departure + 7 日で最初から設定されているため、
 * ここで別途 revoke する必要は無い（管理者が延長した場合はその延長を尊重する）。
 */
class CompressGuestIdImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('compress-guest-id-images'))->expireAfter(900)];
    }

    public function handle(GuestIdImageStore $store): void
    {
        $threshold = now()->subDays(7)->toDateString();

        GuestRegistration::query()
            ->whereNotNull('id_image_path')
            ->where('id_image_compressed', false)
            ->whereHas('booking', fn ($q) => $q->whereDate('departure', '<', $threshold))
            ->chunkById(50, function ($registrations) use ($store): void {
                foreach ($registrations as $registration) {
                    try {
                        $store->compress($registration->id_image_path);
                        $registration->update(['id_image_compressed' => true]);
                    } catch (Throwable $e) {
                        Log::error('CompressGuestIdImagesJob failed for registration', [
                            'registration_id' => $registration->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
