<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** from => allowed next. A new review waits for staff; staff publish or reject it; the author may withdraw it; staff may take a published one down. */
    private const FLOW = [
        'pending' => ['published', 'rejected', 'withdrawn'],
        'published' => ['withdrawn', 'rejected'],
    ];

    public function up(): void
    {
        // A customer's review of something they really bought: one per order line. Nothing is shown until staff publish it. The text is written once and never edited.
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 100)->nullable();
            $table->text('body');
            $table->char('locale', 2);                                  // the language it was written in (en | bn), shown as written, never translated
            $table->string('author_name', 60)->nullable();             // "Amina R.", taken when it was written; never the full name
            $table->string('status', 10)->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('moderated_at', 6)->nullable();
            $table->string('rejection_reason', 255)->nullable();        // what staff told the author
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('order_item_id');                            // one review per order line, however many times it is submitted
            $table->index(['product_id', 'status', 'id']);             // a product page: the published reviews, newest first
            $table->index(['status', 'id']);                            // the moderation queue
            $table->index(['user_id', 'id']);                           // one customer's own reviews (and the duplicate-text check)
            $table->index('variant_id');                                // the foreign key
        });
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_check CHECK (rating BETWEEN 1 AND 5 AND locale IN ('en','bn') AND status IN ('pending','published','rejected','withdrawn') AND ((status = 'rejected') = (rejection_reason IS NOT NULL)))");
        StatusFlow::install('reviews', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('reviews');
        Schema::dropIfExists('reviews');
    }
};
