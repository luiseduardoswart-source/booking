<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('cancellation_policy')->default('moderate')->after('house_rules');
            $table->text('cancellation_policy_text')->nullable()->after('cancellation_policy');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['cancellation_policy', 'cancellation_policy_text']);
        });
    }
};
