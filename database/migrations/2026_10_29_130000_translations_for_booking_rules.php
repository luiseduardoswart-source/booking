<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name of a booking rule is visible to guests ("High season:
 * minimum stay is 7 nights") and can therefore be filled in per language, in the same
 * way as the other translatable fields (see HasTranslations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->json('name_translations')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->dropColumn('name_translations');
        });
    }
};
