<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed fields of the booking form: per field required, optional or
 * hidden (fixed_fields: key => mode), instead of only a list of hidden
 * fields. What was hidden stays hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('fixed_fields')->nullable()->after('fields');
        });

        foreach (DB::table('forms')->whereNotNull('hidden_fields')->get(['id', 'hidden_fields']) as $form) {
            $hidden = array_filter((array) json_decode((string) $form->hidden_fields, true), 'is_string');

            if ($hidden !== []) {
                DB::table('forms')->where('id', $form->id)->update([
                    'fixed_fields' => json_encode(array_fill_keys(array_values($hidden), 'hidden')),
                ]);
            }
        }

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('hidden_fields');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('hidden_fields')->nullable()->after('fields');
        });

        foreach (DB::table('forms')->whereNotNull('fixed_fields')->get(['id', 'fixed_fields']) as $form) {
            $hidden = array_keys(array_filter((array) json_decode((string) $form->fixed_fields, true), fn ($mode) => $mode === 'hidden'));

            if ($hidden !== []) {
                DB::table('forms')->where('id', $form->id)->update(['hidden_fields' => json_encode($hidden)]);
            }
        }

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('fixed_fields');
        });
    }
};
