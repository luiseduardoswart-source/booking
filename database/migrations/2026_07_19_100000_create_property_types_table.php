<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();     // waarde in properties.type
            $table->string('name');               // Nederlandse naam
            $table->json('name_translations')->nullable();
            $table->timestamps();
        });

        // De voorheen vaste typen blijven bestaan als beheerbare rijen, plus
        // eventuele afwijkende waarden die al op accommodaties staan.
        $existing = DB::table('properties')->distinct()->pluck('type')->filter()->all();

        foreach (array_unique(array_merge(['holiday_home', 'apartment', 'room'], $existing)) as $slug) {
            DB::table('property_types')->insertOrIgnore([
                'slug' => $slug,
                // At the time the names were Dutch; a later migration
                // (types_english_as_source_language) turns them into English.
                'name' => isset(\App\Models\PropertyType::DEFAULT[$slug])
                    ? \Illuminate\Support\Facades\Lang::get(\App\Models\PropertyType::DEFAULT[$slug], [], 'nl')
                    : ucfirst($slug),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_types');
    }
};
