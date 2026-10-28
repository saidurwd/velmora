<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $created_by
 * @property string $filename
 * @property string $stored_path
 * @property bool $dry_run
 * @property string $status
 * @property int $total_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $error_count
 * @property list<array{line: int, sku: string, outcome: string, errors: list<array{key: string, params: array<string, string>}>}>|null $results
 * @property string|null $failure
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
class CatalogImport extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'results' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
