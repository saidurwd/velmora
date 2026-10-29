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
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 64)->unique();                       // stored upper-case; the column collation also ignores case
            $table->string('description', 255)->nullable();             // staff note
            $table->string('type', 16);                                 // percent | fixed | free_shipping
            $table->bigInteger('value')->default(0);                    // percent: basis points (1000 = 10%); fixed: minor units; free_shipping: 0
            $table->bigInteger('max_discount_minor')->nullable();       // cap for a percent coupon
            $table->bigInteger('min_spend_minor')->default(0);          // against the goods the coupon applies to
            $table->string('scope', 12)->default('all');                // all | products | categories
            $table->dateTime('starts_at', 6)->nullable();
            $table->dateTime('ends_at', 6)->nullable();                 // exclusive
            $table->unsignedInteger('usage_limit')->nullable();         // total redemptions allowed
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percent','fixed','free_shipping'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_scope_check CHECK (scope IN ('all','products','categories'))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_value_check CHECK (value >= 0 AND (type <> \'percent\' OR value <= 10000) AND min_spend_minor >= 0 AND (max_discount_minor IS NULL OR max_discount_minor >= 0))');
        // the database is the last line of defence against over-redemption, whatever the application does
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_usage_check CHECK (usage_limit IS NULL OR used_count <= usage_limit)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_window_check CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('coupon_products', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_categories', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['coupon_id', 'category_id']);
        });

        // One row per coupon use. `redeemer` is who used it: "u:<user id>" or "p:<sha256 of the phone>" for guests.
        // order_id gets its foreign key when the orders table exists (Orders module migration).
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('redeemer', 80)->nullable();
            $table->bigInteger('amount_minor');
            $table->dateTime('redeemed_at', 6);

            $table->unique(['coupon_id', 'order_id']);     // an order uses a coupon once; a retried order cannot double-redeem
            $table->index(['coupon_id', 'redeemer']);      // per-customer limit check
        });

        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_amount_check CHECK (amount_minor >= 0)');
        AppendOnly::protect('coupon_redemptions');

        // Automatic cart-level promotions: no code, applied when the cart qualifies.
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('type', 12);                                 // percent | fixed
            $table->bigInteger('value');
            $table->bigInteger('max_discount_minor')->nullable();
            $table->bigInteger('min_spend_minor')->default(0);          // whole-cart goods value
            $table->dateTime('starts_at', 6)->nullable();
            $table->dateTime('ends_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (type IN ('percent','fixed'))");
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_value_check CHECK (value > 0 AND (type <> \'percent\' OR value <= 10000) AND min_spend_minor >= 0 AND (max_discount_minor IS NULL OR max_discount_minor >= 0))');

        Schema::create('promotion_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label', 160);                               // shown in the cart: "10% off orders over ৳2,000"
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['promotion_id', 'locale']);
        });
    }

    public function down(): void
    {
        foreach (['promotion_translations', 'promotions', 'coupon_redemptions', 'coupon_categories', 'coupon_products', 'coupons'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
