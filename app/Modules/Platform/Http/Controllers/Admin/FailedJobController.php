<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\ForgetFailedJob;
use App\Modules\Platform\Actions\RetryFailedJob;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Services\FailedJobs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The failed jobs. Looking = queues.view; sending one back or forgetting it = queues.manage. The payload is never shown. */
class FailedJobController
{
    public function index(Request $request, FailedJobs $jobs): View
    {
        $before = $request->validate(['before' => ['nullable', 'integer', 'min:1']])['before'] ?? null;

        return view('platform::admin.ops.failed-jobs', ['page' => $jobs->page($before === null ? null : (int) $before)]);
    }

    public function retry(Request $request, RetryFailedJob $retry, int $failed): RedirectResponse
    {
        return $this->run($request, fn (Actor $actor) => $retry->handle($actor, $failed), 'retried');
    }

    public function forget(Request $request, ForgetFailedJob $forget, int $failed): RedirectResponse
    {
        return $this->run($request, fn (Actor $actor) => $forget->handle($actor, $failed), 'forgotten');
    }

    /** @param  callable(Actor): void  $do */
    private function run(Request $request, callable $do, string $done): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        try {
            $do(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name));
        } catch (OpsException $e) {
            return redirect()->route('admin.ops.failed')->withErrors(['ops' => __('platform::ops.errors.'.$e->reason)]);
        }

        return redirect()->route('admin.ops.failed')->with('status', __('platform::ops.failed.'.$done));
    }
}
