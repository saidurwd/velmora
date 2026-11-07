<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FLOW = [
        'requested' => ['running', 'failed'],
        'running' => ['ready', 'failed'],
        'ready' => ['expired'],
    ];

    public function up(): void
    {
        // A staff member's request for a report as a CSV file. A worker builds it in the background; the finished file waits on the private disk until it expires.
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('report_code', 40);
            $table->json('filters');                          // what the report was narrowed to (period, channel, method, language)
            $table->char('locale', 2);                        // the language of the column headings
            $table->boolean('with_finance');                  // the requester could see finance columns when asking: the file holds them and only someone who still can may download it
            $table->boolean('with_pii');                      // the requester could see personal data unmasked
            $table->string('status', 10)->default('requested');
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('file_key', 120)->nullable();      // random name on the private disk
            $table->string('error_code', 30)->nullable();     // a word, never the exception text
            $table->unsignedSmallInteger('downloads')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('last_downloaded_at', 6)->nullable();

            $table->index(['requested_by', 'id']);            // "my exports", newest first
            $table->index(['status', 'expires_at']);          // the daily clean-up of expired files and stuck runs
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_check CHECK (status IN ('requested','running','ready','failed','expired') AND (status <> 'ready' OR (file_key IS NOT NULL AND expires_at IS NOT NULL AND finished_at IS NOT NULL AND row_count IS NOT NULL)) AND (status <> 'failed' OR error_code IS NOT NULL))");
        StatusFlow::install('report_exports', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('report_exports');
        Schema::dropIfExists('report_exports');
    }
};
