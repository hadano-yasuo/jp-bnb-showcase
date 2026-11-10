<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 予約ごとの宿泊者名簿（旅館業法対応）。1予約に対して N 人分保存する。
 *
 * 業務ルール:
 * - 日本人: 登録は必須ではないが、任意で本人確認画像を保存できる
 * - 日本人以外: 全員分の身分証画像必須
 *
 * カラムは Google Form（現行運用）と同じ項目を踏襲。
 * 画像は Cloudflare R2 (disk=r2) に保存し path のみ格納。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('guest_number');
            $table->string('name');
            $table->string('address');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('occupation')->nullable();
            $table->string('nationality');
            $table->string('phone', 64)->nullable();
            $table->string('previous_stay')->nullable();
            $table->string('next_destination')->nullable();
            $table->string('id_image_path')->nullable();
            $table->boolean('id_image_compressed')->default(false);
            $table->string('submitter_ip', 45)->nullable();
            $table->string('submitter_user_agent', 512)->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->index(['booking_id', 'guest_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_registrations');
    }
};
