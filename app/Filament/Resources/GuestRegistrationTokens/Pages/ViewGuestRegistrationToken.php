<?php

namespace App\Filament\Resources\GuestRegistrationTokens\Pages;

use App\Filament\Resources\GuestRegistrationTokens\GuestRegistrationTokenResource;
use App\Models\GuestRegistration;
use App\Models\GuestRegistrationToken;
use App\Services\GuestIdImage\GuestIdImageDuplicateDetector;
use App\Services\GuestIdImage\GuestIdImageStore;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * トークン単体の詳細ページ。予約情報と提出済みゲスト情報を一覧表示する。
 * AI が判定した verdict を管理者が上書き（承認/却下/AI判定に戻す）できる。
 */
class ViewGuestRegistrationToken extends ViewRecord
{
    protected static string $resource = GuestRegistrationTokenResource::class;

    protected string $view = 'filament.resources.guest-registration-tokens.view';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var GuestRegistrationToken $token */
        $token = $this->record;
        $token->load(['booking.listing', 'booking.guestRegistrations.adminReviewer', 'manuallyCompletedBy']);

        $imageStore = app(GuestIdImageStore::class);
        $imageUrls = [];
        foreach ($token->booking->guestRegistrations as $registration) {
            if ($registration->id_image_path !== null) {
                try {
                    $imageUrls[$registration->id] = $imageStore->temporaryUrl($registration->id_image_path);
                } catch (\Throwable) {
                    $imageUrls[$registration->id] = null;
                }
            }
        }

        // 各ゲスト画像に対して重複検出（SHA-256 完全一致 + AI 抽出氏名一致）
        $duplicateDetector = app(GuestIdImageDuplicateDetector::class);
        $duplicates = $duplicateDetector->detectBatch($token->booking->guestRegistrations);

        return [
            'token' => $token,
            'booking' => $token->booking,
            'registrations' => $token->booking->guestRegistrations,
            'imageUrls' => $imageUrls,
            'publicUrl' => url("/register/{$token->token}"),
            'duplicates' => $duplicates,
        ];
    }

    public function approveRegistration(int $registrationId, ?string $reason = null): void
    {
        $registration = $this->findRegistration($registrationId);
        if ($registration === null) {
            return;
        }

        $registration->update([
            'admin_verdict' => GuestRegistration::VERDICT_OK,
            'admin_verdict_reason' => $reason,
            'admin_reviewed_at' => now(),
            'admin_reviewed_by' => auth()->id(),
        ]);

        Notification::make()
            ->title(sprintf('#%d %s を承認しました', $registration->guest_number, $registration->name))
            ->success()
            ->send();
    }

    public function rejectRegistration(int $registrationId, ?string $reason = null): void
    {
        $registration = $this->findRegistration($registrationId);
        if ($registration === null) {
            return;
        }

        $registration->update([
            'admin_verdict' => GuestRegistration::VERDICT_INVALID,
            'admin_verdict_reason' => $reason,
            'admin_reviewed_at' => now(),
            'admin_reviewed_by' => auth()->id(),
        ]);

        Notification::make()
            ->title(sprintf('#%d %s を却下しました', $registration->guest_number, $registration->name))
            ->warning()
            ->send();
    }

    public function clearAdminVerdict(int $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);
        if ($registration === null) {
            return;
        }

        $registration->update([
            'admin_verdict' => null,
            'admin_verdict_reason' => null,
            'admin_reviewed_at' => null,
            'admin_reviewed_by' => null,
        ]);

        Notification::make()
            ->title(sprintf('#%d %s の管理者判定を解除しました（AI 判定に戻ります）', $registration->guest_number, $registration->name))
            ->info()
            ->send();
    }

    private function findRegistration(int $registrationId): ?GuestRegistration
    {
        /** @var GuestRegistrationToken $token */
        $token = $this->record;

        return $token->booking->guestRegistrations()->find($registrationId);
    }
}
