<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公開HP からの問い合わせ・直接予約リクエストで開設される、
 * ホスト⇔ゲスト間のプライベート通信チャネル。memory: project_self_hp_channel
 *
 * - token: /chat/{token} で参照される 48 文字ハッシュ (GuestRegistrationToken と同構造)
 * - booking_id: 予約成立時 (beds24 write scope 追加後) にリンク、それまで NULL
 * - expires_at: 開設から +90日、予約成立時に departure+7日 に延長
 * - guest_locale: 送信メール本文の言語 (5言語対応)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_channels', function (Blueprint $table) {
            $table->id();
            $table->string('token', 48)->unique();
            $table->string('guest_name', 255)->nullable();
            $table->string('guest_email', 255);
            $table->string('guest_locale', 8)->default('ja');
            $table->foreignId('booking_id')
                ->nullable()
                ->constrained('bookings')
                ->nullOnDelete();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->index('guest_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_channels');
    }
};
