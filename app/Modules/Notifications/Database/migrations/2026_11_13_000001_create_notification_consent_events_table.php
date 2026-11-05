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
        // The history of what a customer agreed to: one row each time their yes or no for marketing on a channel CHANGES (the current answer stays in
        // notification_preferences). Proof that a yes was given, when, and where; never edited, never deleted. It holds no name, address or number: after a
        // customer is erased it still points at the anonymised account and identifies nobody.
        Schema::create('notification_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 5);                                   // email | sms
            $table->boolean('granted');                                     // yes or no
            $table->string('source', 20);                                   // account | checkout | staff | link
            $table->dateTime('created_at', 6);

            $table->index(['user_id', 'id']);                               // one customer's history, newest first (the export and the staff view)
        });
        DB::statement("ALTER TABLE notification_consent_events ADD CONSTRAINT notification_consent_events_check CHECK (channel IN ('email','sms') AND source IN ('account','checkout','staff','link'))");
        AppendOnly::protect('notification_consent_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_consent_events');
    }
};
