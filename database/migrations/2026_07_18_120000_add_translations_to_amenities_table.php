<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            // Name per language, e.g. {"en": "Sauna", "de": "Sauna"}. Empty = fall back
            // to the built-in translation or the Dutch name.
            $table->json('name_translations')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('name_translations');
        });
    }
};
