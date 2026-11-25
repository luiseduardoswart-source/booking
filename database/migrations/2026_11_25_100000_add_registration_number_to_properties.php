<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registration number of a short-term rental (for example the CIN in
 * Italy, the registro único in Spain or the number of a Dutch city). Many
 * places require it in every advert; it is shown on the accommodation page
 * and on the documents. Per accommodation: there is no default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('registration_number', 100)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('registration_number');
        });
    }
};
