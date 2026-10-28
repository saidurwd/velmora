<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Services and digital goods never have stock: enforced by the database, not only by forms.
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'product_variants_physical_stock_'.strtolower($event);
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `product_variants` FOR EACH ROW
                BEGIN
                    IF NEW.track_inventory = 1 AND (SELECT product_type FROM products WHERE id = NEW.product_id) <> 'physical' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only physical products can track inventory';
                    END IF;
                END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `product_variants_physical_stock_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `product_variants_physical_stock_update`');
    }
};
