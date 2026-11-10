<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 将来の自動返信精度向上用の学習データ。
 *
 * action:
 * - sent            送信された（final_text あり、draft と一致 or 編集済み）
 * - cleared         クリアされた（不採用）
 * - regenerated     再生成された（前 draft の何が悪かったかの示唆）
 *
 * final_text と draft_id.draft_text の diff を edit_diff に保存しておくと
 * 後で人間 or 別 AI が「この修正パターン」を学習できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained('message_drafts')->nullOnDelete();
            $table->string('action', 32);
            $table->text('final_text')->nullable();
            $table->text('edit_diff')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['message_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_signals');
    }
};
