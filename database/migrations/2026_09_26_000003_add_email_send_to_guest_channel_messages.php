<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゲストチャットからのメール送信ログを beds24 messages と同じ形式で保存する。
 *
 * - email_subject / emailed_to / emailed_at: メール送信メタデータ
 * - system 行かつ emailed_to != null なら「📧 メール送信 → xxx@yyy (件名)」で表示
 *   （beds24 側 messages テーブル / ShowConversation blade と揃える）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->string('email_subject', 255)->nullable()->after('message');
            $table->string('emailed_to', 255)->nullable()->after('email_subject');
            $table->timestampTz('emailed_at')->nullable()->after('emailed_to');
        });
    }

    public function down(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->dropColumn(['email_subject', 'emailed_to', 'emailed_at']);
        });
    }
};
