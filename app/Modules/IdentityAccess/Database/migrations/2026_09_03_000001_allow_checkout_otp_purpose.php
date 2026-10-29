<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PURPOSES = "'login','register','verify_phone','verify_email','reset','guest_access','change_phone'";

    public function up(): void
    {
        DB::statement('ALTER TABLE otp_challenges DROP CONSTRAINT otp_purpose_check');
        DB::statement('ALTER TABLE otp_challenges ADD CONSTRAINT otp_purpose_check CHECK (purpose IN ('.self::PURPOSES.",'checkout_cod'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM otp_challenges WHERE purpose = 'checkout_cod'");
        DB::statement('ALTER TABLE otp_challenges DROP CONSTRAINT otp_purpose_check');
        DB::statement('ALTER TABLE otp_challenges ADD CONSTRAINT otp_purpose_check CHECK (purpose IN ('.self::PURPOSES.'))');
    }
};
