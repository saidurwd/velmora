<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('author_id')->nullable();   // staff user; NULL for the system
            $table->string('visibility', 10);                      // internal | customer
            $table->text('body');
            $table->dateTime('created_at', 6);

            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'id']);                     // notes of one order, oldest first
        });

        DB::statement("ALTER TABLE order_notes ADD CONSTRAINT order_notes_visibility_check CHECK (visibility IN ('internal','customer'))");

        // A correction is a new note; what was said is never rewritten. (author_id may be nulled when a staff account is deleted.)
        AppendOnly::protect('order_notes', ['author_id']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `order_notes_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `order_notes_no_update`');
        Schema::dropIfExists('order_notes');
    }
};
