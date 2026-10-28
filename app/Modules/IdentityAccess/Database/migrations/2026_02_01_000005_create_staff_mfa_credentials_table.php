<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_mfa_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->text('secret');                          // encrypted TOTP secret
            $table->json('recovery_codes')->nullable();      // array of {hash, used_at}
            $table->dateTime('confirmed_at', 6)->nullable(); // null until the first valid code is entered
            $table->unsignedBigInteger('last_used_step')->nullable(); // TOTP time-step replay protection
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_mfa_credentials');
    }
};
