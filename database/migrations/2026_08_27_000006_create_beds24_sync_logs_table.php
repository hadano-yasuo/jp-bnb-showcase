<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds24_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 32)->index();
            $table->string('status', 16);
            $table->foreignId('credential_id')->constrained('beds24_credentials')->cascadeOnDelete();
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('credit_consumed')->nullable();
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds24_sync_logs');
    }
};
