<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per refresh token the mobile API ever issued (Phase 11, ADR P11-2). A sign-in on a device is a FAMILY (family_id, a ULID shown to the
 * customer as the "session id"); every refresh marks the row `used` and adds a new `active` row to the same family, so a token seen twice is proof
 * of theft and the whole family is revoked. Only the SHA-256 of the token is stored. Rows are kept after use (that is how reuse is recognised)
 * and removed by the daily clean-up a week after they expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('family_id', 26)->charset('ascii')->collation('ascii_bin');
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('status', 10)->default('active');
            $table->string('device_name', 80);
            $table->string('platform', 10)->nullable();
            $table->dateTime('signed_in_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('used_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->dateTime('expires_at', 6);

            $table->unique('token_hash');                       // POST /auth/refresh: find the presented token and claim it with a conditional UPDATE
            $table->index('family_id');                         // theft or logout: end every row of one sign-in
            $table->index(['user_id', 'status']);               // GET /me/tokens and logout-all: this person's live sign-ins
            $table->index('expires_at');                        // the daily clean-up of rows a week past their expiry
        });

        DB::statement("ALTER TABLE refresh_tokens ADD CONSTRAINT refresh_tokens_check CHECK (status IN ('active','used','revoked') AND (platform IS NULL OR platform IN ('android','ios')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
