<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Exceptions\SavedFilterException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** A staff member's own saved filters. Personal: every read and every change is scoped to the owner. */
final class SavedFilters
{
    public const MAX_PER_SCREEN = 20;

    public function __construct(private readonly SavedFilterScreens $screens, private readonly Clock $clock) {}

    /** @return list<array{id: string, name: string, query: string, url: string}> */
    public function for(int $userId, string $screen): array
    {
        if (! $this->screens->has($screen)) {
            return [];
        }

        return array_values(DB::table('saved_filters')->where('user_id', $userId)->where('screen', $screen)->orderBy('name')->get(['public_id', 'name', 'query'])->map(fn ($r): array => [
            'id' => (string) $r->public_id, 'name' => (string) $r->name, 'query' => (string) $r->query, 'url' => route($this->screens->route($screen)).'?'.$r->query,
        ])->all());
    }

    /**
     * Keeps the filters the screen allows, checked by the screen's own rules, empty ones dropped, sorted by name.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws SavedFilterException
     */
    public function clean(string $screen, array $input): array
    {
        $allowed = [];
        foreach ($this->screens->keys($screen) as $key) {
            $value = $input[$key] ?? null;
            is_string($value) && trim($value) !== '' && $allowed[$key] = trim($value);
        }

        Validator::make($allowed, $this->screens->rules($screen))->fails() && throw SavedFilterException::invalid();
        $allowed === [] && throw SavedFilterException::nothingToSave();

        ksort($allowed);

        return $allowed;
    }

    /**
     * The same filters as a sorted query string.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws SavedFilterException
     */
    public function canonical(string $screen, array $input): string
    {
        return http_build_query($this->clean($screen, $input), '', '&', PHP_QUERY_RFC3986);
    }

    /** @param  array<string, mixed>  $input  @throws SavedFilterException */
    public function save(int $userId, string $screen, string $name, array $input): string
    {
        $this->screens->has($screen) || throw SavedFilterException::unknownScreen();
        $name = BanglaText::input($name);
        (mb_strlen($name) >= 1 && mb_strlen($name) <= 60) || throw SavedFilterException::name();
        $query = $this->canonical($screen, $input);

        return Cache::lock('saved-filters:'.$userId, 5)->block(3, function () use ($userId, $screen, $name, $query): string {
            DB::table('saved_filters')->where('user_id', $userId)->where('screen', $screen)->count() < self::MAX_PER_SCREEN || throw SavedFilterException::tooMany();
            DB::table('saved_filters')->where('user_id', $userId)->where('screen', $screen)->where('name', $name)->exists() && throw SavedFilterException::duplicate();

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $id = strtolower((string) Str::ulid());
            DB::table('saved_filters')->insert(['public_id' => $id, 'user_id' => $userId, 'screen' => $screen, 'name' => $name, 'query' => $query, 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
    }

    /** Only the owner's own filter goes. @return bool whether one was removed */
    public function delete(int $userId, string $publicId): bool
    {
        return DB::table('saved_filters')->where('user_id', $userId)->where('public_id', $publicId)->delete() === 1;
    }
}
