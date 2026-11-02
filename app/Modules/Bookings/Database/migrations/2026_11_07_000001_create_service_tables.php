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
        // A product that is sold as a booking. The rules that apply to every package of it.
        Schema::create('service_definitions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('product_id');
            $table->string('location_type', 12)->default('at_provider');
            $table->unsignedInteger('lead_time_min')->default(60);         // the soonest a customer may book: this long from now
            $table->unsignedSmallInteger('max_horizon_days')->default(60); // the furthest ahead a customer may book
            $table->unsignedSmallInteger('hold_minutes')->default(15);     // how long a chosen slot is kept while the customer pays
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('product_id');                                  // one definition per product
        });
        DB::statement("ALTER TABLE service_definitions ADD CONSTRAINT service_definitions_check CHECK (location_type IN ('at_provider','online') AND lead_time_min <= 525600 AND max_horizon_days BETWEEN 1 AND 365 AND hold_minutes BETWEEN 5 AND 120)");

        // One thing a customer can pick (30 minutes, 60 minutes...): it IS a product variant, so price, tax and the cart work as for any product.
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('service_definitions')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id');
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedSmallInteger('buffer_before_min')->default(0);  // kept free before the booking
            $table->unsignedSmallInteger('buffer_after_min')->default(0);   // kept free after it
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->unique('variant_id');                                  // a variant is one package; also finds a variant's package
            $table->index('service_id');                                   // the packages of a service
        });
        DB::statement('ALTER TABLE service_packages ADD CONSTRAINT service_packages_check CHECK (duration_min BETWEEN 5 AND 1440 AND buffer_before_min <= 240 AND buffer_after_min <= 240)');

        // Who or what is booked: a person, a room, a vehicle, a team.
        Schema::create('service_resources', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('type', 10)->default('staff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE service_resources ADD CONSTRAINT service_resources_check CHECK (type IN ('staff','room','vehicle','team'))");

        Schema::create('service_resource_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('service_definitions')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('service_resources')->cascadeOnDelete();

            $table->unique(['service_id', 'resource_id']);                 // a resource serves a service once
            $table->index('resource_id');                                  // the services a resource serves
        });
    }

    public function down(): void
    {
        foreach (['service_resource_links', 'service_resources', 'service_packages', 'service_definitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
