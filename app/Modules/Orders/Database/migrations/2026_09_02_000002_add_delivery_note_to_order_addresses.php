<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // "Call before you come", "leave with the guard": the courier needs it, so the order keeps what the customer wrote.
        DB::statement('ALTER TABLE order_addresses ADD COLUMN delivery_note VARCHAR(255) NULL AFTER landmark');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_addresses DROP COLUMN delivery_note');
    }
};
