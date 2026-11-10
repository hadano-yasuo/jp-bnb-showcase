<?php

namespace App\Jobs;

use App\Models\GuestRegistration;
use App\Services\GuestIdImage\GuestIdImageVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ゲスト身分証画像を Claude Vision で検証する非同期 Job。
 *
 * フォーム送信直後に dispatch される。R2 → Claude → DB のパイプラインで
 * 数秒 〜 数十秒かかる可能性があるためリクエストラインからは切り離す。
 *
 * リトライは 2 回まで。それでも失敗したら verdict=error でマークして
 * Filament に手動確認を促す（GuestIdImageVerifier 内で処理される）。
 */
class VerifyGuestIdImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public readonly int $guestRegistrationId) {}

    public function handle(GuestIdImageVerifier $verifier): void
    {
        $registration = GuestRegistration::find($this->guestRegistrationId);
        if ($registration === null) {
            Log::info('VerifyGuestIdImageJob: registration not found (deleted?)', [
                'id' => $this->guestRegistrationId,
            ]);

            return;
        }

        if ($registration->id_image_path === null) {
            return;
        }

        try {
            $verifier->verify($registration);
        } catch (Throwable $e) {
            Log::error('VerifyGuestIdImageJob: verifier threw', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
