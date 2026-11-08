<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->string('type');                    // page_view | photo_view | quote | booking
            $table->string('session_id')->nullable();  // om unieke bezoekers te tellen
            $table->string('locale', 8)->nullable();
            $table->string('device', 16)->nullable();  // mobile | desktop | tablet
            $table->string('referrer_host')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['property_id', 'type']);
            $table->index('media_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_events');
    }
};
