<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nightly_rates', function (Blueprint $table) {
            // Discount percentage with which this nightly price came about
            // (for display; the already-reduced price is in price_cents).
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('nightly_rates', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
