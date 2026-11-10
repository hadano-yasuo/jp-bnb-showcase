<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「返信不要」フラグ。ホストが「このメッセージには返信しない」と判断したら true。
 * 未返信バッジの判定に使う（予約の最新メッセージが guest かつ no_reply_needed=false のみカウント）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('no_reply_needed')->default(false)->after('is_read');
            $table->index('no_reply_needed');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['no_reply_needed']);
            $table->dropColumn('no_reply_needed');
        });
    }
};
