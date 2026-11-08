<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An accommodation can have several hosts: the link moves from the
 * properties.host_id column to a pivot table, just like with the amenities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_property', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();

            $table->primary(['property_id', 'host_id']);
        });

        DB::table('host_property')->insertUsing(
            ['property_id', 'host_id'],
            DB::table('properties')->whereNotNull('host_id')->select('id', 'host_id'),
        );

        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('host_id')->nullable()->after('ical_token')->constrained()->nullOnDelete();
        });

        // Back to one host per accommodation: the first link stays.
        DB::table('host_property')
            ->selectRaw('property_id, min(host_id) as host_id')
            ->groupBy('property_id')
            ->get()
            ->each(fn ($link) => DB::table('properties')->where('id', $link->property_id)->update(['host_id' => $link->host_id]));

        Schema::dropIfExists('host_property');
    }
};
