<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('target', 254);          // E.164 phone or lower-cased email
            $table->string('purpose', 24);          // login | register | verify_phone | verify_email | reset | guest_access | change_phone
            $table->string('code_hash', 255);       // never the code itself
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->string('ip', 45)->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            // verify: latest open challenge for (target, purpose)
            $table->index(['target', 'purpose', 'created_at']);
            // per-IP send throttling and purge
            $table->index(['ip', 'created_at']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_purpose_check CHECK (purpose IN ('login','register','verify_phone','verify_email','reset','guest_access','change_phone'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
