<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental terms, house rules and the own cancellation text per language, in
 * the same way as the other translatable fields (see HasTranslations): the
 * Dutch text stays in the existing column, the translations go next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('rental_terms_translations')->nullable()->after('rental_terms');
            $table->json('house_rules_translations')->nullable()->after('house_rules');
            $table->json('cancellation_policy_text_translations')->nullable()->after('cancellation_policy_text');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['rental_terms_translations', 'house_rules_translations', 'cancellation_policy_text_translations']);
        });
    }
};
