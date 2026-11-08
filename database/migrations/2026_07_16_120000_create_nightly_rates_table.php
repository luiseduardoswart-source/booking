<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price per single night (calendar date). Wins over season/base price.
        // Source 'manual' (by hand in the admin) or 'pricelabs' (dynamic API).
        Schema::create('nightly_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('price_cents');
            $table->string('source')->default('manual'); // manual | pricelabs
            $table->timestamps();

            $table->unique(['property_id', 'date']);
            $table->index(['property_id', 'date', 'source']);
        });

        Schema::table('properties', function (Blueprint $table) {
            // Link to a PriceLabs listing for the dynamic price sync.
            $table->string('pricelabs_listing_id')->nullable()->after('ical_token');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('pricelabs_listing_id');
        });

        Schema::dropIfExists('nightly_rates');
    }
};
