<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\HomeCollections;
use App\Modules\Catalog\Models\Collection;

final class DatabaseHomeCollections implements HomeCollections
{
    public function options(string $locale): array
    {
        $out = [];
        foreach (Collection::query()->with('translations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(200)->get() as $collection) {
            $out[(string) $collection->public_id] = (string) $collection->translate('name', $locale);
        }

        return $out;
    }

    public function exists(string $publicId): bool
    {
        return Collection::query()->where('public_id', $publicId)->where('is_active', true)->exists();
    }
}
