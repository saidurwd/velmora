<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** from => allowed next. open = waiting for the shop; answered = waiting for the customer; closed can be opened again by a reply within the reopen window. */
    private const FLOW = [
        'open' => ['answered', 'closed'],
        'answered' => ['open', 'closed'],
        'closed' => ['open'],
    ];

    public function up(): void
    {
        // A customer's conversation with the shop. Customers only (a guest uses the contact page). Staff see an internal reference, never an auto-increment id.
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->char('reference', 9)->charset('ascii')->collation('ascii_bin')->unique();   // "K7M2-Q9XD": what the customer quotes
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();                                   // an order of this customer's, when it is about one
            $table->string('subject', 150);
            $table->string('category', 12);                                                       // order | delivery | payment | return | account | other
            $table->string('status', 10)->default('open');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('locale', 2)->default('en');                                             // the language the customer wrote in: replies are announced in it
            $table->dateTime('sla_due_at', 6);                                                    // when the first answer is due
            $table->dateTime('first_responded_at', 6)->nullable();
            $table->dateTime('last_message_at', 6);
            $table->dateTime('closed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->index(['status', 'sla_due_at']);                                              // the staff queue, and the overdue check
            $table->index(['user_id', 'last_message_at']);                                        // a customer's own tickets, most recent first
            $table->index(['assignee_id', 'status']);                                             // "my tickets"
            $table->index('order_id');                                                            // the foreign key; tickets about one order
        });
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_check CHECK (category IN ('order','delivery','payment','return','account','other') AND status IN ('open','answered','closed') AND ((status = 'closed') = (closed_at IS NOT NULL)))");
        StatusFlow::install('support_tickets', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('support_tickets');
        Schema::dropIfExists('support_tickets');
    }
};
