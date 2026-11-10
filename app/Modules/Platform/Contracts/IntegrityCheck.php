<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\IntegrityResult;

/**
 * One data-integrity check, owned by the module whose tables it reads. It must only READ, stay within a bounded amount of work, and never
 * try to fix what it finds. Modules register their checks with the IntegrityRegistry in their service provider.
 */
interface IntegrityCheck
{
    /** A stable snake_case code; also the key of its translated name (platform::ops.checks.<code>). */
    public function code(): string;

    public function run(): IntegrityResult;
}
