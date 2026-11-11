<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Engagement\Bulk\AssignTickets;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\RatingStats;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/* Slice 5b: reviews approved or rejected in bulk and tickets handed out in bulk, each row through the module's own Action. */

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    foreach (['bulk', 'bulk-run'] as $bucket) {
        RateLimiter::clear($bucket);
    }
    $this->moderator = catalogStaff(['dashboard.view', 'reviews.moderate']);
    $this->agent = catalogStaff(['dashboard.view', 'support.view', 'support.reply']);
    $this->colleague = catalogStaff(['dashboard.view', 'support.view', 'support.reply']);
    $this->manager = catalogStaff(['dashboard.view', 'support.view', 'support.reply', 'support.manage']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->customers = 0;
    $this->review = function (int $rating = 5) {
        $this->customers++;
        $user = User::factory()->create(['name' => 'Amina Rahman', 'email' => "c{$this->customers}@example.com"]);
        app(CustomerProfiles::class)->ensureForUser($user->id, 'Amina Rahman', $user->email, null);
        $order = ($this->orderFor)($user->id, [$this->ebook['variantId'] => 1]);
        ($this->payOrder)($order->id);

        return app(SubmitReview::class)->handle($user->id, $order->public_id, $this->ebook['publicId'], $rating, 'Title', 'Review number '.uniqid().' of the e-book, very clear.');
    };
    $this->status = fn (string $id) => (string) DB::table('reviews')->where('public_id', $id)->value('status');
    $this->recount = function (): array {
        $stars = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach (DB::table('reviews')->where('product_id', $this->ebook['product']->id)->where('status', 'published')->pluck('rating') as $rating) {
            $stars[(int) $rating]++;
        }

        return $stars;
    };
    $this->stats = fn () => app(RatingStats::class)->forProduct((int) $this->ebook['product']->id);
    $this->ticket = fn (array $over = []): string => (string) DB::table('support_tickets')->where('id', DB::table('support_tickets')->insertGetId($over + [
        'public_id' => strtolower((string) Str::ulid()), 'reference' => strtoupper(substr(md5(uniqid()), 0, 4).'-'.substr(md5(uniqid()), 0, 4)), 'user_id' => $this->agent->id,
        'subject' => 'Where is my parcel?', 'category' => 'delivery', 'status' => 'open', 'locale' => 'en', 'sla_due_at' => now()->addHours(24), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->value('public_id');
    $this->assignee = fn (string $id) => DB::table('support_tickets')->where('public_id', $id)->value('assignee_id');
    $this->run = fn (User $u, string $action, array $ids, array $p = []) => ($this->as)($u)->post("/admin/bulk/{$action}/run", ['ids' => $ids] + ($p === [] ? [] : ['p' => $p]));
    $this->batch = fn () => json_decode((string) DB::table('audit_events')->where('action', 'bulk.run')->orderByDesc('id')->value('after'), true);
});

describe('reviews', function () {
    it('approves the chosen reviews and counts their stars', function () {
        $ids = [($this->review)(5), ($this->review)(4), ($this->review)(2)];

        ($this->run)($this->moderator, 'reviews.approve', $ids)->assertRedirect('/admin/bulk/result');

        foreach ($ids as $id) {
            expect(($this->status)($id))->toBe('published');
        }
        expect(($this->recount)())->toBe([1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1]);
        expect(($this->batch)())->toMatchArray(['action' => 'reviews.approve', 'requested' => 3, 'done' => 3, 'failed' => 0]);
    });

    it('refuses a review that was already dealt with, row by row, without touching the others or the star counts', function () {
        $a = ($this->review)(5);
        $b = ($this->review)(1);
        ($this->run)($this->moderator, 'reviews.approve', [$b]);

        $this->flushSession()->actingAs($this->moderator, 'admin');
        $this->post('/admin/bulk/reviews.approve/run', ['ids' => [$a, $b]])->assertRedirect('/admin/bulk/result');
        $this->get('/admin/bulk/result')->assertOk()->assertSee('1 done, 1 not done.')->assertSee('already been dealt with');

        expect(($this->status)($a))->toBe('published')->and(($this->recount)())->toBe([1 => 1, 2 => 0, 3 => 0, 4 => 0, 5 => 1]);
    });

    it('rejects the chosen reviews with one reason for every author, and insists on the reason', function () {
        $ids = [($this->review)(3), ($this->review)(3)];

        ($this->run)($this->moderator, 'reviews.reject', $ids)->assertSessionHasErrors('p.reason');
        ($this->run)($this->moderator, 'reviews.reject', $ids, ['reason' => '   '])->assertSessionHasErrors('p.reason');
        foreach ($ids as $id) {
            expect(($this->status)($id))->toBe('pending');
        }

        ($this->run)($this->moderator, 'reviews.reject', $ids, ['reason' => 'Please leave out the shop\'s competitors.'])->assertRedirect('/admin/bulk/result');

        foreach ($ids as $id) {
            expect(($this->status)($id))->toBe('rejected')->and(DB::table('reviews')->where('public_id', $id)->value('rejection_reason'))->toBe('Please leave out the shop\'s competitors.');
        }
        expect(($this->batch)()['params'])->toBe(['reason' => 'Please leave out the shop\'s competitors.']);
    });

    it('asks for the reason on the confirmation page, before anything is run', function () {
        $id = ($this->review)(3);

        ($this->as)($this->moderator)->post('/admin/bulk/reviews.reject/confirm', ['ids' => [$id]])->assertOk()->assertSee('name="p[reason]"', false);
        expect(($this->status)($id))->toBe('pending');
    });

    it('takes a published review down and takes its stars out of the count', function () {
        $a = ($this->review)(5);
        $b = ($this->review)(1);
        ($this->run)($this->moderator, 'reviews.approve', [$a, $b]);

        ($this->run)($this->moderator, 'reviews.reject', [$a], ['reason' => 'Not about the product.']);

        expect(($this->status)($a))->toBe('rejected')->and(($this->recount)())->toBe([1 => 1, 2 => 0, 3 => 0, 4 => 0, 5 => 0]);
    });

    it('is only for moderators, and the queue shows the boxes only to them', function () {
        $id = ($this->review)();

        ($this->run)($this->agent, 'reviews.approve', [$id])->assertForbidden();
        ($this->run)($this->agent, 'reviews.reject', [$id], ['reason' => 'x'])->assertForbidden();
        expect(($this->status)($id))->toBe('pending');

        ($this->as)($this->moderator)->get('/admin/reviews')->assertOk()->assertSee('Approve the chosen reviews')->assertSee('name="ids[]"', false);
    });
});

describe('tickets', function () {
    it('lets anyone who may reply take the chosen tickets for themselves', function () {
        $ids = [($this->ticket)(), ($this->ticket)(), ($this->ticket)()];

        ($this->run)($this->agent, 'support.assign', $ids, ['assignee' => 'me'])->assertRedirect('/admin/bulk/result');

        foreach ($ids as $id) {
            expect(($this->assignee)($id))->toBe($this->agent->id);
        }
        expect(DB::table('audit_events')->where('action', 'support.assigned')->count())->toBe(3)->and(($this->batch)())->toMatchArray(['action' => 'support.assign', 'requested' => 3, 'done' => 3]);
    });

    it('does not offer, and will not accept, giving tickets to someone else unless the person manages support', function () {
        $id = ($this->ticket)();

        ($this->as)($this->agent)->post('/admin/bulk/support.assign/confirm', ['ids' => [$id]])->assertOk()->assertDontSee($this->colleague->name)->assertSee('Me');
        ($this->run)($this->agent, 'support.assign', [$id], ['assignee' => (string) $this->colleague->id])->assertSessionHasErrors('p.assignee');
        ($this->run)($this->agent, 'support.assign', [$id], [])->assertSessionHasErrors('p.assignee');

        expect(($this->assignee)($id))->toBeNull();
    });

    it('lets a manager hand tickets to a colleague who can reply, and to nobody else', function () {
        $id = ($this->ticket)();
        $outsider = catalogStaff(['dashboard.view', 'catalog.view']);

        ($this->as)($this->manager)->post('/admin/bulk/support.assign/confirm', ['ids' => [$id]])->assertOk()->assertSee($this->colleague->name);
        ($this->run)($this->manager, 'support.assign', [$id], ['assignee' => (string) $outsider->id])->assertSessionHasErrors('p.assignee');
        ($this->run)($this->manager, 'support.assign', [$id], ['assignee' => (string) $this->colleague->id])->assertRedirect('/admin/bulk/result');

        expect(($this->assignee)($id))->toBe($this->colleague->id);
    });

    it('refuses to hand a ticket to someone else for a person without the manage right, even when called directly', function () {
        $id = ($this->ticket)();
        $actor = new Actor(ActorType::Staff, $this->agent->id, $this->agent->public_id, 'Agent');
        $bulk = app(AssignTickets::class);

        expect(fn () => $bulk->run($actor, $this->agent, $id, ['assignee' => (string) $this->colleague->id]))->toThrow(EngagementException::class, 'not_allowed');
        expect(($this->assignee)($id))->toBeNull();
    });

    it('is only for staff who may reply', function () {
        $viewer = catalogStaff(['dashboard.view', 'support.view']);
        $id = ($this->ticket)();

        ($this->run)($viewer, 'support.assign', [$id], ['assignee' => 'me'])->assertForbidden();
        ($this->as)($viewer)->get('/admin/support')->assertOk()->assertDontSee('name="ids[]"', false);
        expect(($this->assignee)($id))->toBeNull();
    });

    it('renders in Bangla', function () {
        $id = ($this->ticket)();

        ($this->as)($this->agent)->withSession(['locale' => 'bn'])->post('/admin/bulk/support.assign/confirm', ['ids' => [$id]])->assertOk();
        expect(trans('engagement::bulk.assign', [], 'bn'))->toBe('বাছাই করা টিকেটগুলোর দায়িত্ব নিন')->and(trans('engagement::bulk.me', [], 'bn'))->toBe('আমি');
    });
});
