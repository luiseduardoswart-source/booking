<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // bijv. "Hoogseizoen 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('weekend_price_cents')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'start_date', 'end_date']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_nights');
            $table->unsignedTinyInteger('percentage'); // 0-100
            $table->timestamps();

            $table->unique(['property_id', 'min_nights']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('rate_seasons');
    }
};
