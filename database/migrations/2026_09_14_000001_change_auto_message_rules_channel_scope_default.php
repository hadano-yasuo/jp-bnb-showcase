<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto_message_rules.channel_scope のデフォルト値を 'airbnb' → 'all' に変更。
 * ルール作成時は「特定チャネル限定」ではなく「全チャネル対象」を初期値にする方針。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->string('channel_scope', 32)->default('all')->change();
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->string('channel_scope', 32)->default('airbnb')->change();
        });
    }
};
