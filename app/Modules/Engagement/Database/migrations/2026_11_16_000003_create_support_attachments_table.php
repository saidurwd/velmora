<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files sent with a message. The file lives on the private disk under a random key; the name the sender used is kept only to show, after cleaning.
        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('message_id')->constrained('support_messages')->restrictOnDelete();
            $table->string('original_name', 100);
            $table->string('mime', 30);                                                           // image/webp (re-encoded) | application/pdf
            $table->unsignedInteger('size_bytes');
            $table->string('storage_key', 120)->unique();
            $table->dateTime('created_at', 6);

            $table->index('message_id');                                                          // the files of one message
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
    }
};
