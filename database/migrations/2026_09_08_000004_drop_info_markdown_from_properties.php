<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * properties.info_markdown を削除。
 *
 * Property は admin UI から非表示にし、beds24 sync 用の内部データとして扱う方針に変更。
 * 物件共通情報は buildings.info_markdown に集約。
 * ユーザー確認済み: 現状 properties.info_markdown には何も入力されていない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('info_markdown');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('info_markdown')->nullable()->after('notes');
        });
    }
};
