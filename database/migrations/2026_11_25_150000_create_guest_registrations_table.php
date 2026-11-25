<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The details of every traveller that many countries require a host to pass
 * on to the police or the city (Spain, Italy, Portugal, Germany...). The
 * guest fills them in through a link; the document number and the address
 * are stored encrypted, and everything is erased a number of days after
 * departure (per accommodation, as the local rules require).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('first_name', 100);
            $table->string('last_name', 150);
            $table->string('sex', 10);
            $table->date('birth_date');
            $table->char('birth_country', 2)->nullable();
            $table->char('nationality', 2);
            $table->string('document_type', 30)->nullable();
            $table->text('document_number')->nullable();
            $table->char('document_country', 2)->nullable();
            $table->char('residence_country', 2)->nullable();
            $table->string('residence_city', 100)->nullable();
            $table->text('residence_address')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'position']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('guest_registration')->nullable()->after('id_cancel_refund');
            $table->unsignedSmallInteger('guest_registration_keep_days')->nullable()->after('guest_registration');
        });

        DB::table('properties')->where('is_master', true)->update(['guest_registration' => false, 'guest_registration_keep_days' => 30]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['guest_registration', 'guest_registration_keep_days']);
        });

        Schema::dropIfExists('guest_registrations');
    }
};
