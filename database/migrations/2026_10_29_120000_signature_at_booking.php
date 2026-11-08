<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital signature under the rental terms (see App\Support\Signature).
 * A simple electronic signature (eIDAS); the evidentiary value comes from what
 * is recorded here: who, when, from which IP address and with which
 * browser, plus a fingerprint (SHA-256) of exactly the signed text.
 * The image itself is in protected storage, not in public/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('signature_name')->nullable()->after('terms_snapshot');
            $table->string('signature_path')->nullable()->after('signature_name');
            $table->char('signature_hash', 64)->nullable()->after('signature_path');
            $table->timestamp('signed_at')->nullable()->after('signature_hash');
            $table->string('signature_ip', 45)->nullable()->after('signed_at');
            $table->string('signature_user_agent', 512)->nullable()->after('signature_ip');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'signature_name', 'signature_path', 'signature_hash',
                'signed_at', 'signature_ip', 'signature_user_agent',
            ]);
        });
    }
};
