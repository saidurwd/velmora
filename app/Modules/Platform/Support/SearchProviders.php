<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\SearchProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;

/** The kinds of result the staff search box can find, as the modules registered them. */
final class SearchProviders
{
    /** @var list<class-string<SearchProvider>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<SearchProvider>  $class */
    public function add(string $class): void
    {
        in_array($class, $this->classes, true) || $this->classes[] = $class;
    }

    /** @return list<SearchProvider> only the ones this person may use */
    public function visibleFor(?Authenticatable $user): array
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return [];
        }

        $all = array_map(fn (string $class): SearchProvider => $this->container->make($class), $this->classes);

        return array_values(array_filter($all, fn (SearchProvider $p): bool => collect($p->permissions())->every(fn (string $perm): bool => $user->can($perm) === true)));
    }
}
