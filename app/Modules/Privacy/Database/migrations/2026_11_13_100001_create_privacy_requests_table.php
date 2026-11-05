<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** from => allowed next. export: requested -> ready | failed, ready -> expired. erasure: requested -> approved | rejected | cancelled, approved -> done | blocked, blocked -> approved | rejected. */
    private const FLOW = [
        'requested' => ['ready', 'failed', 'approved', 'rejected', 'cancelled'],
        'ready' => ['expired'],
        'approved' => ['done', 'blocked'],
        'blocked' => ['approved', 'rejected'],
    ];

    public function up(): void
    {
        // A customer's request for a copy of their data (export) or for their account to be erased (erasure). The customer never sees a number, only the public id.
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 10);                                 // export | erasure
            $table->string('status', 12)->default('requested');
            $table->string('open_key', 40)->nullable()->unique();      // "<user>:<type>" while the request is open, NULL once closed: the database allows one open request of each kind per customer
            $table->char('locale', 2)->default('en');                   // the language the customer asked in: messages about the request use it
            $table->string('reason', 500)->nullable();                  // the customer's own words (erasure)
            $table->string('decision_reason', 255)->nullable();         // staff's words when rejecting or approving
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('failure', 255)->nullable();                 // why an export failed, or what blocks an erasure (codes, never personal data)
            $table->string('file_key', 120)->nullable();                // the encrypted export file on the private disk
            $table->unsignedInteger('file_bytes')->nullable();
            $table->dateTime('requested_at', 6);
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();             // an export file is deleted after this
            $table->timestamps(6);

            $table->index(['user_id', 'type', 'requested_at']);        // a customer's own requests; the "one export a day" rule
            $table->index(['type', 'status', 'requested_at']);         // the staff queue: erasure requests waiting for a decision
            $table->index(['status', 'expires_at']);                   // the daily clean-up of expired export files
        });
        DB::statement("ALTER TABLE privacy_requests ADD CONSTRAINT privacy_requests_check CHECK (type IN ('export','erasure') AND status IN ('requested','ready','failed','expired','approved','rejected','cancelled','blocked','done') AND ((type = 'export' AND status IN ('requested','ready','failed','expired')) OR (type = 'erasure' AND status IN ('requested','approved','rejected','cancelled','blocked','done'))))");
        StatusFlow::install('privacy_requests', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('privacy_requests');
        Schema::dropIfExists('privacy_requests');
    }
};
