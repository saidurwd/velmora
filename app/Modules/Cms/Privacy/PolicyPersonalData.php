<?php

declare(strict_types=1);

namespace App\Modules\Cms\Privacy;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * A customer's policy acceptances: part of their data copy, and anonymised (not deleted) when they are erased,
 * so the shop can still show how many people accepted a version without knowing who.
 */
final class PolicyPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly Clock $clock) {}

    public function section(): string
    {
        return 'policy_acceptances';
    }

    public function export(int $userId): array
    {
        $default = (string) config('app.default_locale');

        return DB::table('policy_acceptances as a')->join('cms_policy_releases as r', 'r.id', '=', 'a.release_id')
            ->join('cms_page_versions as v', 'v.id', '=', 'r.version_id')
            ->leftJoin('cms_page_version_translations as t', fn ($j) => $j->on('t.version_id', '=', 'v.id')->where('t.locale', '=', $default))
            ->where('a.user_id', $userId)->orderBy('a.id')
            ->get(['r.kind', 't.title', 'v.number as version', 'a.channel', 'a.accepted_at'])
            ->map(fn ($row) => ['policy' => $row->kind, 'title' => $row->title, 'version' => (int) $row->version, 'channel' => $row->channel, 'accepted_at' => $row->accepted_at])->all();
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        DB::table('policy_acceptances')->where('user_id', $userId)->update(['user_id' => null, 'erased_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }
}
