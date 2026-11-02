<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many licence keys this right is owed (one per unit bought, when the product has a pool). 0 = the product has no keys.
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('keys_needed')->default(0)->after('downloads_used');
        });
    }

    public function down(): void
    {
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dropColumn('keys_needed');
        });
    }
};
