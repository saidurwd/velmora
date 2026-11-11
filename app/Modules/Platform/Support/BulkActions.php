<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\BulkAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;

/** The bulk actions the modules registered. A person sees, and can run, only those whose permissions they hold. */
final class BulkActions
{
    public const MAX_ROWS = 50;

    /** @var list<class-string<BulkAction>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<BulkAction>  $class */
    public function add(string $class): void
    {
        in_array($class, $this->classes, true) || $this->classes[] = $class;
    }

    public function find(string $code): ?BulkAction
    {
        foreach ($this->classes as $class) {
            $action = $this->container->make($class);
            if ($action->code() === $code) {
                return $action;
            }
        }

        return null;
    }

    /** @return list<BulkAction> the ones on this list that this person may run */
    public function forScreen(string $screen, ?Authenticatable $user): array
    {
        $all = array_map(fn (string $class): BulkAction => $this->container->make($class), $this->classes);

        return array_values(array_filter($all, fn (BulkAction $a): bool => $a->screen() === $screen && $this->allowed($user, $a)));
    }

    public function allowed(?Authenticatable $user, BulkAction $action): bool
    {
        return $user !== null && method_exists($user, 'can') && collect($action->permissions())->every(fn (string $p): bool => $user->can($p) === true);
    }
}
