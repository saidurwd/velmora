<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax charged on delivery or the COD fee (item tax lives on order_items). Empty while VAT is off.
        Schema::create('order_tax_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 12);
            $table->unsignedInteger('rate_bp');
            $table->bigInteger('taxable_minor');
            $table->bigInteger('tax_minor');
            $table->dateTime('created_at', 6);

            $table->index('order_id');
        });

        DB::statement("ALTER TABLE order_tax_lines ADD CONSTRAINT order_tax_lines_check CHECK (kind IN ('shipping','cod_fee') AND taxable_minor >= 0 AND tax_minor >= 0)");

        AppendOnly::protect('order_tax_lines');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `order_tax_lines_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `order_tax_lines_no_update`');
        Schema::dropIfExists('order_tax_lines');
    }
};
