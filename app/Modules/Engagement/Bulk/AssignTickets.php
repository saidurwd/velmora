<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Bulk;

use App\Modules\Engagement\Actions\AssignSupportTicket;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\Platform\Contracts\BulkAction;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands the chosen tickets to someone, each through the same Action as the ticket page. Anyone who may reply can take tickets for themselves; giving them to someone else needs
 * `support.manage` (the choice is not even offered otherwise, and the Action refuses it anyway).
 */
final class AssignTickets implements BulkAction
{
    public function __construct(private readonly AssignSupportTicket $assign, private readonly StaffDirectory $staff) {}

    public function code(): string
    {
        return 'support.assign';
    }

    public function screen(): string
    {
        return 'support';
    }

    public function label(): string
    {
        return 'engagement::bulk.assign';
    }

    public function permissions(): array
    {
        return ['support.reply'];
    }

    public function fields(Authenticatable $by): array
    {
        $options = ['me' => __('engagement::bulk.me')];
        if (method_exists($by, 'can') && $by->can('support.manage') === true) {
            foreach ($this->staff->withPermission('support.reply') as $id => $name) {
                $id === (int) $by->getAuthIdentifier() || $options[(string) $id] = $name;
            }
        }

        return [['name' => 'assignee', 'type' => 'select', 'label' => 'engagement::bulk.assignee', 'required' => true, 'options' => $options]];
    }

    public function describe(array $ids): array
    {
        // unique public_id: the chosen rows
        return DB::table('support_tickets')->whereIn('public_id', $ids)->pluck('reference', 'public_id')->map(fn ($r): string => (string) $r)->all();
    }

    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void
    {
        $choice = $params['assignee'] ?? 'me';
        $this->assign->handle($actor, $id, $choice === 'me' ? $actor->id : (int) $choice, method_exists($by, 'can') && $by->can('support.manage') === true);
    }

    public function explain(Throwable $e): string
    {
        return $e instanceof EngagementException && \Lang::has('engagement::support.errors.'.$e->reason) ? __('engagement::support.errors.'.$e->reason) : __('platform::bulk.unknown');
    }
}
