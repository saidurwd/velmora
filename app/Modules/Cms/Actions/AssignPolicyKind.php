<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Cms\Exceptions\PolicyException;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Says which page is THE terms (privacy, …) page. Once a page has been released as a policy its kind is fixed, so history stays attached to the right policy. */
final class AssignPolicyKind
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Actor $actor, Page $page, ?PolicyKind $kind): Page
    {
        try {
            $saved = Transactions::run(function () use ($actor, $page, $kind): Page {
                $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

                if ($locked->kind === $kind?->value) {
                    return $locked; // nothing to change
                }

                $released = DB::table('cms_policy_releases')->where('page_id', $locked->id)->exists();
                ! $released || throw PolicyException::kindLocked();

                $locked->forceFill(['kind' => $kind?->value])->save();
                $this->audit->record('cms.policy_assigned', $locked, [], ['kind' => $kind?->value], $actor);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw PolicyException::kindTaken();
        }

        return $saved;
    }
}
