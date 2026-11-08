<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who accepted which release. A customer's acceptance belongs to the customer, a guest's to the order. No IP address, phone or device is kept.
 * Append-only; the one allowed change is erasure: the customer link goes and erased_at is set, so the row stays as an anonymous count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_id')->constrained('cms_policy_releases')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('channel', 12);
            $table->dateTime('accepted_at', 6);
            $table->dateTime('erased_at', 6)->nullable();

            $table->unique(['user_id', 'release_id'], 'policy_acceptances_user_release_unique');   // a customer accepts a release once; "has this customer accepted?"
            $table->unique(['order_id', 'release_id'], 'policy_acceptances_order_release_unique'); // a guest order accepts a release once
            $table->index(['release_id', 'channel'], 'policy_acceptances_release_channel_index');  // staff counts per release and channel
        });

        DB::statement("ALTER TABLE policy_acceptances ADD CONSTRAINT policy_acceptances_channel_check CHECK (channel IN ('signup','checkout','account'))");
        DB::statement('ALTER TABLE policy_acceptances ADD CONSTRAINT policy_acceptances_owner_check CHECK ((user_id IS NULL OR order_id IS NULL) AND (user_id IS NOT NULL OR order_id IS NOT NULL OR erased_at IS NOT NULL))');

        DB::unprepared("CREATE TRIGGER `policy_acceptances_no_delete` BEFORE DELETE ON `policy_acceptances` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'policy_acceptances: DELETE is not allowed'");
        DB::unprepared("CREATE TRIGGER `policy_acceptances_erase_only` BEFORE UPDATE ON `policy_acceptances` FOR EACH ROW BEGIN IF NOT (OLD.user_id IS NOT NULL AND NEW.user_id IS NULL AND OLD.erased_at IS NULL AND NEW.erased_at IS NOT NULL AND OLD.release_id = NEW.release_id AND OLD.order_id <=> NEW.order_id AND OLD.channel = NEW.channel AND OLD.accepted_at = NEW.accepted_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'policy_acceptances: only erasure may change a row'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `policy_acceptances_erase_only`');
        DB::unprepared('DROP TRIGGER IF EXISTS `policy_acceptances_no_delete`');
        Schema::dropIfExists('policy_acceptances');
    }
};
