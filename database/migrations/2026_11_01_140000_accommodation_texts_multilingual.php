<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The texts of an accommodation become multilingual, like the house rules: the
 * Dutch text stays in the column itself and the translations come next to it.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'name_translations' => 'name',
        'short_description_translations' => 'short_description',
        'description_translations' => 'description',
        'meta_title_translations' => 'meta_title',
        'meta_description_translations' => 'meta_description',
    ];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $nextTo) {
                $table->json($column)->nullable()->after($nextTo);
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }
};
