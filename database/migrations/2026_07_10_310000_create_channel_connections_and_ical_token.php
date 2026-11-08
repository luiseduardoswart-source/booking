<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('ical_token', 64)->nullable()->after('meta_description');
        });

        Schema::create('channel_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('ical'); // later: channelmanager/airbnb-api/...
            $table->string('name');                      // bijv. "Airbnb" of "Booking.com"
            $table->string('url', 2048);                 // externe iCal-feed
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_connections');
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('ical_token');
        });
    }
};
