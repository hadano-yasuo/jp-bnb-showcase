<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beds24_room_id')->unique();
            $table->unsignedBigInteger('beds24_property_id')->index();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('floor', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
