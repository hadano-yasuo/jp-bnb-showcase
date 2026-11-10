<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 予約ごとに1件、ゲストが名簿登録フォームへアクセスするためのトークンを発行する。
 *
 * URL 例: https://admin.example.com/register/{token}
 * expires_at はデフォルトで booking.departure + 7日。管理者が手動で延長可能。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')
                ->unique()
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_registration_tokens');
    }
};
