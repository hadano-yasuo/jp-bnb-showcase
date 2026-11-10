<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ルールをカテゴリ (テンプレセット) 単位でまとめて管理するための列。
 * Filament の一覧で category フィルタと「まとめて有効化/無効化」の bulk action
 * を使うためのグルーピング。null=未分類 (既存ルールはこの値で残る)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->string('category', 64)->nullable()->after('name');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
