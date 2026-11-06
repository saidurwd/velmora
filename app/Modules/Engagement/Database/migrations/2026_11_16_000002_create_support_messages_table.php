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
        // What was said. Messages are never edited and the customer cannot delete them. An internal note is for staff only and is never shown to the customer.
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $table->string('author_type', 8);                                                     // customer | staff
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->dateTime('created_at', 6);

            $table->index(['ticket_id', 'id']);                                                   // one conversation, in order
        });
        DB::statement("ALTER TABLE support_messages ADD CONSTRAINT support_messages_check CHECK (author_type IN ('customer','staff') AND (NOT is_internal OR author_type = 'staff'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
