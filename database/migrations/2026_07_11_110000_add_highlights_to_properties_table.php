<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Custom highlight blocks on the detail page (icon + title + text).
            // Empty = automatic blocks (check-in, location, secure payment).
            $table->json('highlights')->nullable()->after('house_rules');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('highlights');
        });
    }
};
