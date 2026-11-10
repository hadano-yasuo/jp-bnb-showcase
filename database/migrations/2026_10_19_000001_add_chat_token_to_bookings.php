<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自社サイト直接予約 (api_source=direct) 用のゲスト向けチャット URL トークン。
 * /chat/booking/{token} でゲストが Message を閲覧・投稿できる。
 * OTA 予約は OTA のメッセンジャーを使うので null のまま。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('chat_token', 48)->nullable()->unique()->after('guest_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique(['chat_token']);
            $table->dropColumn('chat_token');
        });
    }
};
