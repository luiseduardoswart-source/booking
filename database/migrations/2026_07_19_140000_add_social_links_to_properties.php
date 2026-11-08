<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Own social links per property, e.g. {"instagram": "..."}.
            // Empty channel = the general link from the settings.
            $table->json('social_links')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('social_links');
        });
    }
};
