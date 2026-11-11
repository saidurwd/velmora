<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\BulkAction;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\BulkActions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 5b: bulk actions. The rule is in each module's own Action; the bulk run only chooses rows, confirms, runs them one by one and says what happened.
 */

/** A bulk action that fails in a way nobody planned, for one row. */
class ExplodingBulk implements BulkAction
{
    public static ?string $boom = null;

    /** @var list<string> */
    public static array $ran = [];

    public function code(): string
    {
        return 'test.explode';
    }

    public function screen(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'platform::bulk.go';
    }

    public function permissions(): array
    {
        return ['orders.update'];
    }

    public function fields(Authenticatable $by): array
    {
        return [];
    }

    public function describe(array $ids): array
    {
        return array_combine($ids, array_map(fn (string $id): string => 'Row '.$id, $ids));
    }

    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void
    {
        self::$ran[] = $id;
        if ($id === self::$boom) {
            throw new RuntimeException('secret detail that must not be shown');
        }
    }

    public function explain(Throwable $e): string
    {
        return __('platform::bulk.unknown');
    }
}

beforeEach(function () {
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    foreach (['bulk', 'bulk-run'] as $bucket) {
        RateLimiter::clear($bucket);
    }
    $this->staff = fn (array $permissions) => catalogStaff(array_merge(['dashboard.view'], $permissions));
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->me = ($this->staff)(['orders.update']);
    $this->pending = fn (array $over = []): string => (string) DB::table('orders')->where('id', ($this->order)($over + ['status' => 'pending']))->value('public_id');
    $this->statusOf = fn (string $publicId): string => (string) DB::table('orders')->where('public_id', $publicId)->value('status');
    $this->confirm = fn (User $u, array $ids, string $action = 'orders.confirm') => ($this->as)($u)->post("/admin/bulk/{$action}/confirm", ['ids' => $ids]);
    $this->run = fn (User $u, array $ids, string $action = 'orders.confirm', array $p = []) => ($this->as)($u)->post("/admin/bulk/{$action}/run", ['ids' => $ids] + ($p === [] ? [] : ['p' => $p]));
    $this->batches = fn () => DB::table('audit_events')->where('action', 'bulk.run')->orderBy('id')->get();
});

describe('confirming orders in bulk', function () {
    it('shows the chosen rows first and changes nothing until they are confirmed', function () {
        $a = ($this->pending)(['order_number' => 'VM-BULK-1']);
        $b = ($this->pending)(['order_number' => 'VM-BULK-2']);

        ($this->confirm)($this->me, [$a, $b])->assertOk()->assertSee('VM-BULK-1')->assertSee('VM-BULK-2')->assertSee('Please confirm');

        expect(($this->statusOf)($a))->toBe('pending')->and(($this->statusOf)($b))->toBe('pending')->and(($this->batches)())->toHaveCount(0);
    });

    it('confirms each chosen order through the same Action as the single button, with its own history and audit record', function () {
        $ids = [($this->pending)(), ($this->pending)(), ($this->pending)()];

        ($this->run)($this->me, $ids)->assertRedirect('/admin/bulk/result');

        foreach ($ids as $id) {
            expect(($this->statusOf)($id))->toBe('confirmed');
        }
        $orderIds = DB::table('orders')->whereIn('public_id', $ids)->pluck('id');
        expect(DB::table('order_status_history')->whereIn('order_id', $orderIds)->where('axis', 'status')->where('to_state', 'confirmed')->count())->toBe(3);
    });

    it('does what it can: a flagged order or one that cannot be confirmed is refused with its reason and the others still go through', function () {
        $ok = ($this->pending)(['order_number' => 'VM-OK-1']);
        $flagged = ($this->pending)(['order_number' => 'VM-FLAG-1', 'needs_review' => true]);
        $already = ($this->pending)(['order_number' => 'VM-DONE-1', 'status' => 'confirmed']);
        $cancelled = ($this->pending)(['order_number' => 'VM-GONE-1', 'status' => 'cancelled', 'cancelled_at' => now()]);

        ($this->run)($this->me, [$flagged, $ok, $already, $cancelled]);
        $page = ($this->as)($this->me)->withSession(session()->all())->get('/admin/bulk/result');

        expect(($this->statusOf)($ok))->toBe('confirmed')->and(($this->statusOf)($flagged))->toBe('pending')->and(($this->statusOf)($cancelled))->toBe('cancelled');
        $batch = json_decode((string) ($this->batches)()->last()->after, true);
        expect($batch['requested'])->toBe(4)->and($batch['done'])->toBe(1)->and($batch['failed'])->toBe(3);
    });

    it('shows what happened to every row, with the reason for each that was not done', function () {
        $ok = ($this->pending)(['order_number' => 'VM-OK-2']);
        $flagged = ($this->pending)(['order_number' => 'VM-FLAG-2', 'needs_review' => true]);

        $this->flushSession()->actingAs($this->me, 'admin');
        $this->post('/admin/bulk/orders.confirm/run', ['ids' => [$ok, $flagged]])->assertRedirect('/admin/bulk/result');
        $this->get('/admin/bulk/result')->assertOk()->assertSee('1 done, 1 not done.')->assertSee('VM-OK-2')->assertSee('VM-FLAG-2')->assertSee('flagged for review');
    });

    it('counts a row chosen twice once, and leaves out a row that does not exist', function () {
        $a = ($this->pending)();
        $ghost = strtolower((string) Str::ulid());

        ($this->confirm)($this->me, [$a, $a, $ghost])->assertOk()->assertSee('1 of the chosen rows could not be found');
        ($this->run)($this->me, [$a, $a, $ghost]);

        $batch = json_decode((string) ($this->batches)()->last()->after, true);
        expect($batch['requested'])->toBe(2)->and($batch['done'])->toBe(1)->and($batch['failed'])->toBe(1)
            ->and(DB::table('order_status_history')->where('to_state', 'confirmed')->count())->toBe(1);
    });

    it('takes at most fifty rows, and fifty is fine', function () {
        $ids = [];
        foreach (range(1, 51) as $i) {
            $ids[] = ($this->pending)();
        }

        ($this->run)($this->me, $ids)->assertSessionHasErrors('ids');
        ($this->confirm)($this->me, $ids)->assertSessionHasErrors('ids');
        expect(DB::table('orders')->where('status', 'pending')->count())->toBe(51)->and(($this->batches)())->toHaveCount(0);

        ($this->run)($this->me, array_slice($ids, 0, 50))->assertRedirect('/admin/bulk/result');
        expect(DB::table('orders')->where('status', 'confirmed')->count())->toBe(50);
    });

    it('refuses an empty choice and anything that is not a row id', function () {
        ($this->run)($this->me, [])->assertSessionHasErrors('ids');
        ($this->run)($this->me, ['1 OR 1=1'])->assertSessionHasErrors('ids.0');
        ($this->run)($this->me, ['id' => ['nested']])->assertSessionHasErrors();
        expect(($this->batches)())->toHaveCount(0);
    });

    it('writes one extra audit record for the whole run, with who, what, which rows and how it went', function () {
        $a = ($this->pending)();
        $b = ($this->pending)(['needs_review' => true]);

        ($this->run)($this->me, [$a, $b]);

        $rows = ($this->batches)();
        $new = json_decode((string) $rows->first()->after, true);
        expect($rows)->toHaveCount(1)->and((int) $rows->first()->actor_id)->toBe($this->me->id)
            ->and($new)->toMatchArray(['action' => 'orders.confirm', 'requested' => 2, 'done' => 1, 'failed' => 1, 'ids' => [$a, $b]]);
    });

    it('survives a row that fails in a way nobody planned: the others still run and the detail is not shown', function () {
        app(BulkActions::class)->add(ExplodingBulk::class);
        ExplodingBulk::$ran = [];
        $ids = [($this->pending)(), ($this->pending)(), ($this->pending)()];
        ExplodingBulk::$boom = $ids[1];

        $this->flushSession()->actingAs($this->me, 'admin');
        $this->post('/admin/bulk/test.explode/run', ['ids' => $ids])->assertRedirect('/admin/bulk/result');
        $this->get('/admin/bulk/result')->assertOk()->assertSee('2 done, 1 not done.')->assertDontSee('secret detail');
    });
});

describe('who may', function () {
    it('lets in only staff who hold the action\'s own permission, and tells guests, customers and unknown actions apart', function () {
        $a = ($this->pending)();
        $moderator = ($this->staff)(['reviews.moderate']);
        $nobody = ($this->staff)(['catalog.view']);

        ($this->confirm)($moderator, [$a])->assertForbidden();   // may use bulk actions, but not this one
        ($this->run)($moderator, [$a])->assertForbidden();
        ($this->run)($nobody, [$a])->assertForbidden();
        ($this->run)($this->me, [$a], 'orders.nothing')->assertNotFound();
        $this->flushSession();
        auth()->forgetGuards();
        $this->post('/admin/bulk/orders.confirm/run', ['ids' => [$a]])->assertRedirect('/admin/login');
        auth()->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->post('/admin/bulk/orders.confirm/run', ['ids' => [$a]])->assertRedirect('/admin/login');

        expect(($this->statusOf)($a))->toBe('pending')->and(($this->batches)())->toHaveCount(0);
    });

    it('offers each action only where the person may use it', function () {
        ($this->pending)();
        $orders = ($this->staff)(['orders.view', 'orders.update']);
        $viewer = ($this->staff)(['orders.view']);

        ($this->as)($orders)->get('/admin/orders')->assertOk()->assertSee('Confirm the chosen orders')->assertSee('name="ids[]"', false);
        ($this->as)($viewer)->get('/admin/orders')->assertOk()->assertDontSee('Confirm the chosen orders')->assertDontSee('name="ids[]"', false);
        expect(app(BulkActions::class)->forScreen('orders', $viewer))->toBe([]);
    });

    it('is limited in how often it can be used', function () {
        $a = ($this->pending)();

        foreach (range(1, 10) as $i) {
            ($this->run)($this->me, [$a])->assertRedirect();
        }
        ($this->run)($this->me, [$a])->assertStatus(429);
    });

    it('keeps showing the result when the page is refreshed', function () {
        $a = ($this->pending)(['order_number' => 'VM-KEEP-1']);

        $this->flushSession()->actingAs($this->me, 'admin');
        $this->post('/admin/bulk/orders.confirm/run', ['ids' => [$a]]);
        $this->get('/admin/bulk/result')->assertOk()->assertSee('VM-KEEP-1');
        $this->get('/admin/bulk/result')->assertOk()->assertSee('VM-KEEP-1');
    });

    it('sends a person with no result to wait for back to the dashboard', function () {
        ($this->as)($this->me)->get('/admin/bulk/result')->assertRedirect('/admin');
    });
});

describe('in Bangla', function () {
    it('renders the confirmation and the result', function () {
        $a = ($this->pending)(['order_number' => 'VM-BN-1']);

        ($this->as)($this->me)->withSession(['locale' => 'bn'])->post('/admin/bulk/orders.confirm/confirm', ['ids' => [$a]])->assertOk()->assertSee('VM-BN-1');
        expect(trans('platform::bulk.confirm_title', [], 'bn'))->toBe('অনুগ্রহ করে নিশ্চিত করুন')->and(trans('orders::bulk.confirm', [], 'bn'))->toBe('বাছাই করা অর্ডারগুলো নিশ্চিত করুন');
    });
});
