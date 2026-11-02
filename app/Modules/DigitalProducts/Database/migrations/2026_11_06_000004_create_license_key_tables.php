<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The licence keys of one digital product variant, sold one per unit.
        Schema::create('license_key_pools', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('variant_id');                              // one pool per variant; also finds a variant's pool
        });

        Schema::create('license_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pool_id')->constrained('license_key_pools')->restrictOnDelete();
            $table->text('key_encrypted');                             // encrypted with the app key; never stored or logged in the clear
            $table->char('fingerprint', 64);                           // HMAC of the key: finds a repeat without keeping the key
            $table->string('status', 10)->default('available');
            $table->unsignedBigInteger('entitlement_id')->nullable();  // set once, when the key is given to a customer
            $table->dateTime('allocated_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('entitlement_id')->references('id')->on('entitlements')->restrictOnDelete();
            $table->unique(['pool_id', 'fingerprint']);                // the same key is never imported twice into a pool, even by two files at once
            $table->index(['pool_id', 'status', 'id']);                // giving out the lowest available key; counting what is left
            $table->index('entitlement_id');                           // the keys of one right (shown to the customer)
        });

        DB::statement("ALTER TABLE license_keys ADD CONSTRAINT license_keys_check CHECK (status IN ('available','allocated','revoked') AND (status <> 'available' OR entitlement_id IS NULL) AND (status <> 'allocated' OR (entitlement_id IS NOT NULL AND allocated_at IS NOT NULL)))");
        StatusFlow::install('license_keys', 'status', ['available' => ['allocated', 'revoked'], 'allocated' => ['revoked']], ['entitlement_id', 'allocated_at']);
        AppendOnly::protect('license_keys', ['status', 'entitlement_id', 'allocated_at']);   // the key itself, its fingerprint and its pool never change; no row is ever deleted
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `license_keys_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `license_keys_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `license_keys_status_flow`');
        Schema::dropIfExists('license_keys');
        Schema::dropIfExists('license_key_pools');
    }
};
