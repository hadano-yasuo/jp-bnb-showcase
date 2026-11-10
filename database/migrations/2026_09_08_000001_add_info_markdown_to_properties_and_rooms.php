<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ReplyDrafter で system prompt に注入する物件情報。
 *
 * properties.info_markdown: 物件共通（住所、緊急連絡、共通ルール）
 * rooms.info_markdown: 部屋固有（wifi、キーコード、階固有の注意）
 *
 * どちらも自由記述 Markdown（ホストが必要な情報だけ書く）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('info_markdown')->nullable()->after('notes');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->text('info_markdown')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('info_markdown');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('info_markdown');
        });
    }
};
