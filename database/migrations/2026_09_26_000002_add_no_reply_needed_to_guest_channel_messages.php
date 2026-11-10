<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beds24 messages と揃えて、ゲストチャットの最新 guest 発言に対しても
 * 「返信不要」フラグを立てられるようにする。ここが true になった行は
 * 一覧の状態バッジで「返信不要」となり、サイドバーの未対応カウントからも除外される。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->boolean('no_reply_needed')->default(false)->after('message_ja');
            $table->index(['guest_channel_id', 'no_reply_needed']);
        });
    }

    public function down(): void
    {
        Schema::table('guest_channel_messages', function (Blueprint $table) {
            $table->dropIndex(['guest_channel_id', 'no_reply_needed']);
            $table->dropColumn('no_reply_needed');
        });
    }
};
