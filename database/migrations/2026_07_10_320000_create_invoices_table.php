<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained();
            $table->string('invoice_number')->unique(); // F-2026-0001
            $table->date('issued_at');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedTinyInteger('vat_percentage');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
