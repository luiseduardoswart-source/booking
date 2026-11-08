<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('identity_status')->nullable()->after('locale'); // in_afwachting | geverifieerd | mislukt
            $table->string('identity_session_id')->nullable()->after('identity_status');

            $table->index('identity_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['identity_status', 'identity_session_id']);
        });
    }
};
