<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\RunIntegrityChecks;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Support\IntegrityRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The data-integrity checks: the last result of each, and a button to run one or all now. Read-only against business data. */
class IntegrityController
{
    public function index(IntegrityRegistry $registry): View
    {
        $runs = DB::table('integrity_check_runs')->get()->keyBy('code');

        $rows = [];
        foreach ($registry->checks() as $check) {
            $run = $runs->get($check->code());
            $rows[] = [
                'code' => $check->code(), 'status' => $run?->status, 'problems' => (int) ($run->problems ?? 0), 'note' => $run?->note, 'ms' => (int) ($run->duration_ms ?? 0),
                'samples' => $run?->samples === null ? [] : (array) json_decode((string) $run->samples, true),
                'ran' => $run === null ? null : CarbonImmutable::parse((string) $run->ran_at, 'UTC'),
            ];
        }

        return view('platform::admin.ops.integrity', ['rows' => $rows]);
    }

    public function run(Request $request, RunIntegrityChecks $run): RedirectResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/']]);
        $user = $request->user('admin');
        abort_if($user === null, 403);

        try {
            $run->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name), $data['code'] ?? null);
        } catch (OpsException $e) {
            return redirect()->route('admin.ops.integrity')->withErrors(['ops' => __('platform::ops.errors.'.$e->reason)]);
        }

        return redirect()->route('admin.ops.integrity')->with('status', __('platform::ops.integrity.ran'));
    }
}
