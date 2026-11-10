<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * category 列を廃止し、代わりに condition (発火条件) を UI 上のグループ化キーとしても使う。
 *
 * 経緯: category は「テンプレセット分類」として追加したが、実際には condition 値と
 * ほぼ 1:1 で重複していたため冗長だった。condition だけあれば
 * 「同じ発火条件のルールを絞り込んで bulk 有効化」という UX が成立する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->string('category', 64)->nullable()->after('name');
            $table->index('category');
        });
    }
};
