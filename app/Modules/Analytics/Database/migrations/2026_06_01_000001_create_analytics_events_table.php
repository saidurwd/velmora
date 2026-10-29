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
        // First-party, consent-gated storefront events. Deliberately NO user, session, cookie, IP or user-agent columns:
        // each row stands alone, so events cannot be stitched into a profile of one visitor.
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 16);
            $table->string('locale', 5);
            $table->string('path', 255);                       // address without the query string
            $table->char('product_public_id', 26)->nullable(); // view_item
            $table->unsignedInteger('result_count')->nullable(); // search (the words themselves are in search_queries)
            $table->dateTime('created_at', 6);

            $table->index(['name', 'created_at']);   // reports: events per type over time
            $table->index('created_at');              // retention prune
        });

        DB::statement("ALTER TABLE analytics_events ADD CONSTRAINT analytics_events_name_check CHECK (name IN ('page_view','search','view_item'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
