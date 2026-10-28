<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Phone-first identity: email and password become optional (phone+OTP accounts have no password). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY name VARCHAR(150) NULL, MODIFY email VARCHAR(254) NULL, MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY name VARCHAR(255) NOT NULL, MODIFY email VARCHAR(255) NOT NULL, MODIFY password VARCHAR(255) NOT NULL');
    }
};
