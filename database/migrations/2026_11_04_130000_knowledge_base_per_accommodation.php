<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document in the knowledge base can belong to one accommodation. Without an
 * accommodation it applies to all (general information). The chat on the page
 * of an accommodation reads only the general documents and those of that
 * accommodation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
