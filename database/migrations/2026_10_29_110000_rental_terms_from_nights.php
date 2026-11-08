<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From how many nights the rental terms apply (and so must also be signed).
 * Empty on an accommodation = follow the default; empty on the
 * default = 0 = always. House rules and cancellation terms always apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedInteger('rental_terms_min_nights')->nullable()->after('rental_terms_translations');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_terms_min_nights');
        });
    }
};
