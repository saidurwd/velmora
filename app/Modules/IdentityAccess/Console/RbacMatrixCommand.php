<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Permissions\PermissionMatrixDocument;
use Illuminate\Console\Command;

class RbacMatrixCommand extends Command
{
    protected $signature = 'rbac:matrix';

    protected $description = 'Regenerate docs/architecture/PERMISSION_MATRIX.md from the permission catalogue';

    public function handle(): int
    {
        file_put_contents(base_path('docs/architecture/PERMISSION_MATRIX.md'), PermissionMatrixDocument::render());
        $this->info('docs/architecture/PERMISSION_MATRIX.md updated.');

        return self::SUCCESS;
    }
}
