<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Form builder: own forms (for example a contact or request form on a page)
 * and two fixed forms, the booking form and the contact form. Those cannot be
 * deleted; their fixed fields stay, own fields can be added.
 *
 * - forms.fields: the own fields (key, type, label/placeholder/options per
 *   language, required, width). The key is fixed once a field exists.
 * - form_submissions: what visitors sent, per field key with the label at
 *   that moment, so a renamed or removed field keeps old submissions readable.
 * - bookings.form_answers: the answers to the own fields of the booking form.
 * - pages.form_id: the form shown below the content of a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('system', 20)->nullable()->unique();
            $table->string('title')->nullable();
            $table->json('title_translations')->nullable();
            $table->text('success_message')->nullable();
            $table->json('success_message_translations')->nullable();
            $table->string('submit_label')->nullable();
            $table->json('submit_label_translations')->nullable();
            $table->json('fields')->nullable();
            $table->json('hidden_fields')->nullable();
            $table->string('recipients', 1000)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->string('locale', 10)->nullable();
            $table->boolean('handled')->default(false);
            $table->timestamps();
            $table->index(['handled', 'created_at']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->foreignId('form_id')->nullable()->after('content_translations')->constrained()->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->json('form_answers')->nullable()->after('notes');
        });

        $now = now();
        DB::table('forms')->insert([
            ['name' => 'Booking form', 'system' => 'booking', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Contact form', 'system' => 'contact', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('form_answers');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('form_id');
        });

        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
