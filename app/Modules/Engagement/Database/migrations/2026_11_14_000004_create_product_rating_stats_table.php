<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many published reviews a product has at each star rating. Changed by the same transaction that publishes or removes a review, never recomputed over all
        // reviews, so a product page reads one row. The average and the count are worked out from these five numbers.
        Schema::create('product_rating_stats', function (Blueprint $table): void {
            $table->foreignId('product_id')->primary()->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('stars_1')->default(0);
            $table->unsignedInteger('stars_2')->default(0);
            $table->unsignedInteger('stars_3')->default(0);
            $table->unsignedInteger('stars_4')->default(0);
            $table->unsignedInteger('stars_5')->default(0);
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_rating_stats');
    }
};
