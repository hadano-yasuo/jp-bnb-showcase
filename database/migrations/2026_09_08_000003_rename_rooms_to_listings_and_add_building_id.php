<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rooms → listings リネーム（業界用語）と building_id 追加。
 *
 * building_id は nullable。既存 listings は admin が UI で assign する。
 * 新規 listing は SyncMessagesJob が自動作成する時 null で作られる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('rooms', 'listings');

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('id')->constrained('buildings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropColumn('building_id');
        });

        Schema::rename('listings', 'rooms');
    }
};
