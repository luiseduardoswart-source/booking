<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->string('type');                        // percent | fixed
            $table->unsignedInteger('value');              // percentage or cents
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete(); // null = all accommodations
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable(); // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Snapshot of the discount code used (proof, even if the
            // coupon is deleted later).
            $table->string('coupon_code')->nullable()->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });

        Schema::dropIfExists('coupons');
    }
};
