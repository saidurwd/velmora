<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // sha256 of the normalised street line + area + upazila: lets the shop notice one address used by many phones (and block one).
        DB::statement('ALTER TABLE order_addresses ADD COLUMN fingerprint CHAR(64) NULL AFTER phone');
        DB::statement('ALTER TABLE order_addresses ADD INDEX order_addresses_fingerprint_index (fingerprint, created_at)'); // "who else used this address in the last 30 days"
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_addresses DROP INDEX order_addresses_fingerprint_index');
        DB::statement('ALTER TABLE order_addresses DROP COLUMN fingerprint');
    }
};
