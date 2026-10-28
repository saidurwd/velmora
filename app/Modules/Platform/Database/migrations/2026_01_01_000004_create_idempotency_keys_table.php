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
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191);
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->dateTime('expires_at', 6);
            $table->timestamps(6);

            $table->unique(['scope', 'key']); // the duplicate-detection guarantee
            $table->index('expires_at');      // purge job
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_status_check CHECK (status IN ('processing','completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
