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
        // Parcels a person has to look at: a courier word we do not know, delivery attempts used up, a parcel stuck. Raised once per problem.
        Schema::create('shipment_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('type', 30);
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->string('reference', 120);
            $table->json('details');
            $table->string('status', 10)->default('open');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'type']);                         // the exception queue: what is open
            $table->index('shipment_id');                              // the problems of one parcel
            $table->unique(['type', 'reference']);                     // the same problem is raised once
        });

        DB::statement("ALTER TABLE shipment_exceptions ADD CONSTRAINT shipment_exceptions_check CHECK (status IN ('open','resolved','ignored') AND type IN ('unknown_status','attempts_exhausted','stuck','unknown_consignment','returned_to_origin'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_exceptions');
    }
};
