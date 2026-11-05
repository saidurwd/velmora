<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The download history stays, but the address and browser of the person who downloaded must be removable when they are erased. Those two columns
        // may be CLEARED (set to NULL) and never changed to anything else; every other column stays frozen.
        AppendOnly::protect('download_logs', ['ip', 'user_agent']);
        DB::unprepared('DROP TRIGGER IF EXISTS `download_logs_clear_only`');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER `download_logs_clear_only` BEFORE UPDATE ON `download_logs` FOR EACH ROW BEGIN
  IF (NOT (OLD.ip <=> NEW.ip) AND NEW.ip IS NOT NULL) OR (NOT (OLD.user_agent <=> NEW.user_agent) AND NEW.user_agent IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'download_logs: the device details can only be cleared';
  END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `download_logs_clear_only`');
        AppendOnly::protect('download_logs');
    }
};
