<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 100)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->dateTime('created_at', 6);

            // audit viewer: history of one subject, newest first
            $table->index(['subject_type', 'subject_id', 'created_at']);
            // audit viewer: everything one actor did
            $table->index(['actor_id', 'created_at']);
        });

        AppendOnly::protect('audit_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
