<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete(); // null = guest customer
            $table->string('name', 150)->nullable();
            $table->string('phone', 16)->nullable();   // E.164
            $table->string('email', 254)->nullable();
            $table->date('birth_date')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->dateTime('marketing_consent_at', 6)->nullable();
            $table->dateTime('anonymised_at', 6)->nullable();
            $table->timestamps(6);

            // guest checkout: find an existing customer by phone / email
            $table->index('phone');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
