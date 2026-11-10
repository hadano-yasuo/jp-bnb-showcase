<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto_message_rules と listings の多対多。
 * 1 ルールを複数リスティングに紐付けて共通運用する（例: Building B 2 階/3 階共通の予約時挨拶）。
 * 部屋差分は本文中の Blade 変数で吸収する（{{ listing.wifi_password }} 等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_message_rule_listing', function (Blueprint $table) {
            $table->foreignId('auto_message_rule_id')
                ->constrained('auto_message_rules')
                ->cascadeOnDelete();
            $table->foreignId('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->primary(['auto_message_rule_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_message_rule_listing');
    }
};
