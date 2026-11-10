<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto_message_sends に翻訳結果を保存。
 *
 * rendered_body は日本語（テンプレ原文の変数展開後）で、翻訳先言語が ja 以外の場合に
 * rendered_body_translated へ ja→target の翻訳結果を格納する。実送信時はここが送信元。
 * ja の場合は translated_to_lang / rendered_body_translated とも null。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_sends', function (Blueprint $table) {
            $table->text('rendered_body_translated')->nullable()->after('rendered_body');
            $table->string('translated_to_lang', 8)->nullable()->after('rendered_body_translated');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_sends', function (Blueprint $table) {
            $table->dropColumn(['rendered_body_translated', 'translated_to_lang']);
        });
    }
};
