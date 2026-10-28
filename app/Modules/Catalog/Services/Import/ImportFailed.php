<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

use RuntimeException;

/** The whole file is unusable (as opposed to one bad row). `key` is a translation key under catalog::admin.import.failures. */
final class ImportFailed extends RuntimeException
{
    /** @param  array<string, string>  $params */
    public function __construct(public readonly string $key, public readonly array $params = [], string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $key);
    }
}
