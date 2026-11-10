<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beds24_message_id')->unique();
            $table->unsignedBigInteger('beds24_booking_id')->index();
            $table->unsignedBigInteger('beds24_property_id')->index();
            $table->unsignedBigInteger('beds24_room_id')->index();
            $table->unsignedBigInteger('author_owner_id')->nullable();
            $table->string('source', 16)->index();
            $table->text('message');
            $table->boolean('is_read')->default(false)->index();
            $table->timestampTz('sent_at')->index();
            $table->jsonb('raw_payload');
            $table->timestampTz('fetched_at')->useCurrent();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
