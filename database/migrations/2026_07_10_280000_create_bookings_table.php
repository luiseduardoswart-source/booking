<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique(); // BK-2026-0001
            $table->foreignId('property_id')->constrained();

            $table->string('status')->default('new');
            $table->string('payment_status')->default('unpaid');

            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone')->nullable();
            $table->string('guest_address')->nullable();
            $table->string('guest_postal_code')->nullable();
            $table->string('guest_city')->nullable();
            $table->string('guest_country')->default('NL');

            $table->date('arrival_date');
            $table->date('departure_date');
            $table->unsignedTinyInteger('guests');

            $table->text('notes')->nullable();          // notes from the guest
            $table->text('internal_notes')->nullable(); // only visible in the admin

            // Snapshot of the full price breakdown at the time of booking.
            // Later price changes never affect existing bookings.
            $table->json('price_snapshot');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('down_payment_cents');
            $table->unsignedInteger('deposit_cents')->default(0);

            $table->timestamps();

            $table->index(['property_id', 'arrival_date', 'departure_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
