<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic name: the listing ID can belong to any pricing service
        // (PriceLabs, Beyond, Wheelhouse, DPGO), not only PriceLabs.
        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('pricelabs_listing_id', 'external_listing_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('external_listing_id', 'pricelabs_listing_id');
        });
    }
};
