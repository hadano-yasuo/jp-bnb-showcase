<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL 剥奪 OTA (Agoda 等) 向けにメール経由でゲスト登録リンクを送るための対応。
 *
 * - beds24_message_id を nullable 化: メール送信専用の system 行 (beds24 未投稿)
 *   を作成できるようにする。既存の unique 制約は NULL 複数を許容する PostgreSQL
 *   の挙動をそのまま利用 (NULL は distinct 扱い)。
 * - email_subject / emailed_to / emailed_at: メール送信メタデータ。
 *   system 行かつ emailed_to != null なら「📧 メール送信 → xxx@yyy (件名)」で表示。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('beds24_message_id')->nullable()->change();
            $table->string('email_subject', 255)->nullable()->after('message');
            $table->string('emailed_to', 255)->nullable()->after('email_subject');
            $table->timestampTz('emailed_at')->nullable()->after('emailed_to');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['email_subject', 'emailed_to', 'emailed_at']);
            $table->unsignedBigInteger('beds24_message_id')->nullable(false)->change();
        });
    }
};
