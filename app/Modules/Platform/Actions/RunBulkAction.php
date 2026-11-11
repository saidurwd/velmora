<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Contracts\BulkAction;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\BulkResult;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BulkActions;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one bulk action over the chosen rows: at most fifty, each exactly once, one after another, each through the module's own single-row Action (its own transaction, its own audit
 * record). One row failing never stops or undoes the others. One extra audit record says who ran what over which rows, and how it went. Who may (permissions, the row limit) is
 * decided here as well as on the route.
 */
final class RunBulkAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $ids  public ids
     * @param  array<string, string>  $params
     *
     * @throws DomainException too_many | none | not_allowed
     */
    public function handle(Actor $actor, Authenticatable $by, BulkAction $action, array $ids, array $params): BulkResult
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new DomainException('none');
        }
        if (count($ids) > BulkActions::MAX_ROWS) {
            throw new DomainException('too_many');
        }
        if (! app(BulkActions::class)->allowed($by, $action)) {
            throw new DomainException('not_allowed');
        }

        $labels = $action->describe($ids);
        $items = [];
        foreach ($ids as $id) {
            $label = $labels[$id] ?? null;
            if ($label === null) {
                $items[] = ['id' => $id, 'label' => $id, 'ok' => false, 'message' => null];   // not there (any more): nothing was done to it

                continue;
            }
            try {
                $action->run($actor, $by, $id, $params);
                $items[] = ['id' => $id, 'label' => $label, 'ok' => true, 'message' => null];
            } catch (Throwable $e) {
                Log::info('bulk.item_failed', ['action' => $action->code(), 'kind' => $e::class]);
                $items[] = ['id' => $id, 'label' => $label, 'ok' => false, 'message' => $action->explain($e)];
            }
        }

        $result = new BulkResult($action->code(), $items);
        $this->audit->record('bulk.run', 'bulk_action', [], [
            'action' => $action->code(), 'requested' => count($ids), 'done' => $result->done(), 'failed' => $result->failed(), 'ids' => $ids, 'params' => $params,
        ], $actor);

        return $result;
    }
}
