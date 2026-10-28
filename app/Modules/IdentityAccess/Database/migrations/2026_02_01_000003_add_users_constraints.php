<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('customer','staff'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','locked','disabled'))");
        // every account must be reachable by at least one identifier
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_identifier_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');
        // staff always authenticate with email + password (+ MFA); phone-only accounts are customers
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_staff_credentials_check CHECK (account_type <> 'staff' OR (email IS NOT NULL AND password IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['users_account_type_check', 'users_status_check', 'users_identifier_check', 'users_staff_credentials_check'] as $constraint) {
            DB::statement("ALTER TABLE users DROP CONSTRAINT {$constraint}");
        }
    }
};
