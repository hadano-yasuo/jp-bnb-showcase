<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds24_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->text('refresh_token');
            $table->timestampTz('refresh_token_last_used_at')->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->jsonb('ip_whitelist')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds24_credentials');
    }
};
