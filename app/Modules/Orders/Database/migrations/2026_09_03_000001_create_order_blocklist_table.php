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
        // Phones and addresses that may not order. Only a hash of the value is kept (the list is never a copy of customer data);
        // `hint` is just enough for staff to recognise the entry (a masked number, or the area).
        Schema::create('order_blocklist', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);                         // phone | address
            $table->char('value_hash', 64);                     // sha256 of the E.164 phone, or of the normalised address
            $table->string('hint', 80);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_active')->default(true);        // switched off, not deleted: the history stays
            $table->dateTime('expires_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['kind', 'value_hash']);             // "is this phone / address blocked?" at checkout; one entry per value
        });

        DB::statement("ALTER TABLE order_blocklist ADD CONSTRAINT order_blocklist_kind_check CHECK (kind IN ('phone','address'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_blocklist');
    }
};
