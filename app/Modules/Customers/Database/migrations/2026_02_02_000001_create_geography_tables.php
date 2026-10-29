<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reference data (see Database/data/geography/SOURCE.md). Ids are the dataset's own, so seeding is an idempotent upsert.
        Schema::create('divisions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name_en', 60);
            $table->string('name_bn', 60);
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedSmallInteger('division_id');
            $table->string('name_en', 60);
            $table->string('name_bn', 60);

            $table->foreign('division_id')->references('id')->on('divisions')->restrictOnDelete();
            $table->index('division_id'); // address form: districts of a division
        });

        Schema::create('upazilas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedSmallInteger('district_id');
            $table->string('name_en', 80);
            $table->string('name_bn', 80);

            $table->foreign('district_id')->references('id')->on('districts')->restrictOnDelete();
            $table->index('district_id'); // address form: upazilas of a district
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upazilas');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('divisions');
    }
};
