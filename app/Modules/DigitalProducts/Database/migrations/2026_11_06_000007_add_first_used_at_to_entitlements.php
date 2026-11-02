<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the customer first downloaded or first looked at their keys. Set once, never changed: the refund policy "until first use" reads it.
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dateTime('first_used_at', 6)->nullable()->after('granted_at');
        });
        StatusFlow::install('entitlements', 'status', ['active' => ['revoked']], ['first_used_at']);   // the same moves as before, and the new column is set once
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `entitlements_status_flow`');
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dropColumn('first_used_at');
        });
        StatusFlow::install('entitlements', 'status', ['active' => ['revoked']]);
    }
};
