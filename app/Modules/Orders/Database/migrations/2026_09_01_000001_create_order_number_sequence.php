<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Order numbers come from the database so two simultaneous orders can never share one (DATABASE_DESIGN §4.8).
        // NOCACHE: no gaps from a server restart (a rolled-back order may still leave a gap; that is acceptable).
        // IF NOT EXISTS: `migrate:fresh` only drops tables, not sequences.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START WITH 1 INCREMENT BY 1 NOCACHE');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
