<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The right of one customer to download what one order line bought. The owner is the order's owner (looked up through Orders, never copied here).
        Schema::create('entitlements', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id');
            $table->string('status', 10)->default('active');
            $table->unsignedInteger('download_limit')->nullable();     // NULL = unlimited; set when granted from the product's rule or the shop default
            $table->unsignedInteger('downloads_used')->default(0);
            $table->dateTime('expires_at', 6)->nullable();             // NULL = never; "expired" is worked out from this, not stored
            $table->dateTime('granted_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->unique('order_item_id');                           // one right per order line: a repeated or parallel grant cannot make a second
            $table->index('order_id');                                 // the downloads of one order and of one customer's orders (My Library)
            $table->index(['variant_id', 'status']);                   // who owns a product (to tell them about a new version)
        });

        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_check CHECK (status IN ('active','revoked') AND (download_limit IS NULL OR downloads_used <= download_limit) AND (status <> 'revoked' OR revoked_at IS NOT NULL))");
        StatusFlow::install('entitlements', 'status', ['active' => ['revoked']]);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `entitlements_status_flow`');
        Schema::dropIfExists('entitlements');
    }
};
