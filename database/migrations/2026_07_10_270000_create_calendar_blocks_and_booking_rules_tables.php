<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // blocked | maintenance | external
            $table->date('start_date'); // first blocked night
            $table->date('end_date');   // last blocked night (inclusive)
            $table->string('source')->nullable();       // e.g. "airbnb-ical" on import
            $table->string('external_uid')->nullable(); // UID from external iCal feed
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'start_date', 'end_date']);
            $table->unique(['property_id', 'source', 'external_uid']);
        });

        Schema::create('booking_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->date('start_date')->nullable(); // null = always valid
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('min_nights')->default(1);
            $table->unsignedSmallInteger('max_nights')->nullable();
            $table->json('arrival_days')->nullable();   // ISO weekdays 1-7, null = all days
            $table->json('departure_days')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rules');
        Schema::dropIfExists('calendar_blocks');
    }
};
