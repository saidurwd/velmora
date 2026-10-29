<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 40)->nullable();                // "Home", "Office"
            $table->string('recipient', 120);
            $table->string('phone', 16);                            // E.164, via PhoneNumber
            $table->unsignedSmallInteger('division_id');
            $table->unsignedSmallInteger('district_id');
            $table->unsignedSmallInteger('upazila_id');
            $table->string('area', 120)->nullable();                // free text: Dhaka city areas are not a dataset (MVP)
            $table->string('address_line', 255);
            $table->string('landmark', 160)->nullable();
            $table->string('delivery_note', 255)->nullable();
            $table->char('postal_code', 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps(6);

            $table->foreign('division_id')->references('id')->on('divisions')->restrictOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->restrictOnDelete();
            $table->foreign('upazila_id')->references('id')->on('upazilas')->restrictOnDelete();
            $table->index(['customer_id', 'is_default']); // address book of one customer, default first
        });

        // At most one default address per customer, enforced by the database (NULL is "not default" and never collides).
        DB::statement('ALTER TABLE customer_addresses ADD COLUMN default_marker BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_default, customer_id, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE customer_addresses ADD UNIQUE INDEX customer_addresses_one_default (default_marker)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
