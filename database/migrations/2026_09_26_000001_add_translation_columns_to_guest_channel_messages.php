<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beds24 の messages と同じ併記表示（原文＋日本語訳）をゲストチャットでも
 * 実現するため、GuestChannelMessage にも detected_language / message_ja を追加する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->string('detected_language', 16)->nullable()->after('source');
            $table->string('detected_language_label', 64)->nullable()->after('detected_language');
            $table->text('message_ja')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->dropColumn(['detected_language', 'detected_language_label', 'message_ja']);
        });
    }
};
