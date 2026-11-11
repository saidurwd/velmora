<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Exceptions\SavedFilterException;

/**
 * The lists whose filters can be saved. Each module registers its own, naming the list's route, the permission needed to open it and the validation rules of every filter it accepts.
 * Only keys named here are ever stored, so nothing else from an address can end up in the database.
 */
final class SavedFilterScreens
{
    /** @var array<string, array{route: string, permission: string, rules: array<string, mixed>}> */
    private array $screens = [];

    /** @param  array<string, mixed>  $rules  filter key => validation rules */
    public function add(string $screen, string $route, string $permission, array $rules): void
    {
        $this->screens[$screen] = ['route' => $route, 'permission' => $permission, 'rules' => $rules];
    }

    public function has(string $screen): bool
    {
        return isset($this->screens[$screen]);
    }

    public function route(string $screen): string
    {
        return ($this->screens[$screen] ?? throw SavedFilterException::unknownScreen())['route'];
    }

    public function permission(string $screen): string
    {
        return ($this->screens[$screen] ?? throw SavedFilterException::unknownScreen())['permission'];
    }

    /** @return array<string, mixed> */
    public function rules(string $screen): array
    {
        return ($this->screens[$screen] ?? throw SavedFilterException::unknownScreen())['rules'];
    }

    /** @return list<string> */
    public function keys(string $screen): array
    {
        return array_keys($this->rules($screen));
    }
}
