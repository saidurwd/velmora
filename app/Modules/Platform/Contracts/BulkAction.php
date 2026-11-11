<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * One thing staff can do to several rows of a list at once. It never holds the rule itself: `run` calls the module's own Action for ONE row, exactly as the single-row button does,
 * so a bulk run can never do what the single button would refuse. The module registers it in its service provider.
 */
interface BulkAction
{
    /** A stable name, lower-case letters, dots and underscores ("orders.confirm"). */
    public function code(): string;

    /** The list it appears on ("orders", "reviews", "support"). */
    public function screen(): string;

    /** Words for the button: a key in the module's own language files. */
    public function label(): string;

    /** @return list<string> every permission needed (all are required) */
    public function permissions(): array;

    /**
     * What the confirmation page asks besides the rows. Each field: name, type ('text' or 'select'), label (key), required, max (text) or options (select: value => label), by this person.
     *
     * @return list<array{name: string, type: 'text'|'select', label: string, required: bool, max?: int, options?: array<int|string, string>}>
     */
    public function fields(Authenticatable $by): array;

    /**
     * The rows as the person should recognise them, keyed by the row's public id. A row that does not exist is left out.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function describe(array $ids): array;

    /**
     * Does the action to ONE row, in its own transaction. Failing is an exception, and it fails this row only.
     *
     * @param  array<string, string>  $params
     *
     * @throws Throwable
     */
    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void;

    /** The sentence shown against a row that failed (the exception's reason, in words). */
    public function explain(Throwable $e): string;
}
