<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI chat on the site: a knowledge base of own documents that feeds the
 * chat, and the usage per AI call for the costs and the monthly limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('content');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 30);                 // e.g. 'chat'
            $table->string('provider', 20);                // claude | openai | gemini
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_eur', 12, 6)->default(0); // at the rate of that moment
            $table->timestamps();

            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('knowledge_documents');
    }
};
