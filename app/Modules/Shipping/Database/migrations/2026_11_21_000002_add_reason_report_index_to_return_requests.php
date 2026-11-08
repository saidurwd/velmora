<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The returns-by-reason report adds up the requests made in a period, per kind and reason.
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->index(['created_at', 'kind', 'reason_code'], 'return_requests_created_at_kind_reason_index');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropIndex('return_requests_created_at_kind_reason_index');
        });
    }
};
