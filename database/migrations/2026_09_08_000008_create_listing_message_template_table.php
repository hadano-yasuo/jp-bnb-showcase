<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_message_template', function (Blueprint $table) {
            $table->foreignId('message_template_id')->constrained('message_templates')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->primary(['message_template_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_message_template');
    }
};
