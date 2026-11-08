<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accommodations and pages get their own address per language, so each language
 * can be found separately. The slug in the column itself remains the base address.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['properties', 'pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('slug_translations')->nullable()->after('slug');
            });
        }
    }

    public function down(): void
    {
        foreach (['properties', 'pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('slug_translations');
            });
        }
    }
};
