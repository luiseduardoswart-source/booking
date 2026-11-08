<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Which payment this refund pays back — this lets us tell, among other
            // things, whether a deposit has already been refunded.
            $table->foreignId('refunded_payment_id')->nullable()->after('provider_payment_id')->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_payment_id');
        });
    }
};
