<?php

declare(strict_types=1);

namespace App\Modules\Alpha\Actions;

use App\Modules\Beta\Contracts\Thing;
use App\Modules\Beta\Models\Secret; // deliberate BD-11 violation (fixture)

final class DoThing
{
    public function __invoke(Thing $thing): string
    {
        return Secret::class;
    }
}
