<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「滞在中のゲストにのみ送信」フラグ。デフォルト無効。
 * ON にすると scheduled_at が departure（当日終わり）を過ぎている送信を skip する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->boolean('only_when_staying')->default(false)->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->dropColumn('only_when_staying');
        });
    }
};
