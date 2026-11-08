<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Rental terms: default via the master record, overridable per property.
            $table->longText('rental_terms')->nullable()->after('cancellation_policy_text');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Proof of acceptance: when + exactly which text the guest accepted.
            $table->timestamp('terms_accepted_at')->nullable()->after('notes');
            $table->longText('terms_snapshot')->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_terms');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_snapshot']);
        });
    }
};
