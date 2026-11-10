<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Checkout にリダイレクト中の予約データ保持用。
 *
 * フロー:
 *   1. フォーム送信時に pending 行を作る (form_data に全入力を jsonb 保存)
 *   2. Stripe Checkout Session を作成し stripe_session_id を記録
 *   3. ゲスト決済成功 → webhook (checkout.session.completed) 受信
 *   4. webhook 内で form_data 読み出して beds24 POST /bookings → status=paid
 *   5. 失敗時は status=failed、expired は定期 cleanup で status=expired
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_session_id', 128)->nullable()->unique()
                ->comment('Stripe Checkout Session ID (cs_...)');
            $table->jsonb('form_data')
                ->comment('ReserveController::store の validated データ全部 (room_slug, arrival, departure, name, email 等)');
            $table->unsignedInteger('amount_jpy')
                ->comment('決済金額 (JPY、税込)。beds24 の自社サイト用 rate で計算した合計');
            $table->string('status', 16)->default('pending')->index()
                ->comment('pending / paid / failed / expired');
            $table->foreignId('guest_channel_id')->nullable()
                ->constrained('guest_channels')->nullOnDelete()
                ->comment('問い合わせから予約に進んだ場合は元 channel を継続。null なら決済成功後に新規作成');
            $table->foreignId('booking_id')->nullable()
                ->constrained('bookings')->nullOnDelete()
                ->comment('決済成功 + beds24 予約成立後にリンク');
            $table->text('error')->nullable()
                ->comment('status=failed の際の失敗理由 (beds24 エラー等)');
            $table->timestampTz('expires_at')->index()
                ->comment('Stripe Session の有効期限と同期 (24h が標準)。超過したら status=expired');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_reservations');
    }
};
