<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 実世界の建物単位。admin が UI で自由に追加・編集する。
 *
 * beds24 の property とは別概念（beds24 では登録ミスで1建物が複数 property に
 * 分かれていたりする）。listings がこの building に紐づく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('info_markdown')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
