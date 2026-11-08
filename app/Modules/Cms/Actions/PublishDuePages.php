<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Support\Facades\DB;

/** Every minute: scheduled versions whose time has come go live, once each, even with several workers. */
final class PublishDuePages
{
    public const BATCH = 200;

    public function __construct(private readonly TransitionPage $transition, private readonly Clock $clock) {}

    public function handle(): int
    {
        $system = Actor::system('scheduler');
        $published = 0;

        $due = DB::table('cms_pages')->whereNull('deleted_at')->whereNotNull('scheduled_version_id')
            ->where('publish_at', '<=', $this->clock->now()->format('Y-m-d H:i:s.u'))->orderBy('publish_at')->limit(self::BATCH)->pluck('id');

        foreach ($due as $id) {
            $this->transition->publishDue($system, (int) $id) && $published++;
        }

        return $published;
    }
}
