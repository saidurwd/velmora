<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\Contracts\KeyFigureTile;
use App\Modules\Platform\DTOs\TileFigure;
use App\Modules\Platform\Support\DashboardRegistry;
use Illuminate\Routing\Middleware\ThrottleRequests;

/* The staff home page: a greeting, shortcuts the person may use, "Today" (headline figures and their change) and "Needs attention" (queues). */

abstract class FakeTile implements DashboardTile
{
    public function code(): string
    {
        return static::class;
    }

    public function permissions(): array
    {
        return [];
    }
}

final class FakeSalesUp extends FakeTile implements KeyFigureTile
{
    public function label(): string
    {
        return 'Fake sales up';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(150, 'count', 'admin.orders.index', before: 100, series: [10, 20, 40, 50, 80, 100, 150]);
    }
}

final class FakeSalesDown extends FakeTile implements KeyFigureTile
{
    public function label(): string
    {
        return 'Fake sales down';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(25, 'count', 'admin.orders.index', before: 100, series: [100, 90, 70, 60, 50, 100, 25]);
    }
}

final class FakeSalesSame extends FakeTile implements KeyFigureTile
{
    public function label(): string
    {
        return 'Fake sales same';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(7, 'count', 'admin.orders.index', before: 7, series: [7, 7, 7, 7, 7, 7, 7]);
    }
}

final class FakeSalesNew extends FakeTile implements KeyFigureTile
{
    public function label(): string
    {
        return 'Fake sales new';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(12, 'count', 'admin.orders.index', before: 0, series: [0, 0, 0, 0, 0, 0, 12]);
    }
}

final class FakeQueueWaiting extends FakeTile
{
    public function label(): string
    {
        return 'Fake queue waiting';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(5, 'count', 'admin.support.index');
    }
}

final class FakeQueueEmpty extends FakeTile
{
    public function label(): string
    {
        return 'Fake queue empty';
    }

    public function figure(): TileFigure
    {
        return new TileFigure(0, 'count', 'admin.support.index');
    }
}

final class FakeQueueBroken extends FakeTile
{
    public function label(): string
    {
        return 'Fake queue broken';
    }

    public function figure(): TileFigure
    {
        throw new RuntimeException('cannot be worked out');
    }
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware([EnforceSessionPolicy::class, ThrottleRequests::class]);
    $this->owner = User::factory()->staff()->create(['name' => 'Rahim Uddin']);
    $this->owner->assignRole('Super Admin');
    $this->page = fn (?User $u = null, string $url = '/admin') => $this->actingAs($u ?? $this->owner, 'admin')->get($url);
});

it('greets the person by first name and shows today\'s date', function () {
    $html = ($this->page)()->assertOk()->getContent();

    expect($html)->toMatch('/<h1[^>]*>\s*Good (morning|afternoon|evening), Rahim\s*<\/h1>/')->and($html)->toContain(now(config('app.display_timezone'))->translatedFormat('l, j F Y'));
});

it('shows only the shortcuts the person may use', function () {
    $all = ($this->page)()->getContent();
    $support = ($this->page)(catalogStaff(['dashboard.view', 'support.view']))->getContent();

    expect($all)->toContain('>Add a product<')->toContain('>Orders<')->toContain('>Reports<');
    expect($support)->toContain('>Support tickets<')->not->toContain('>Add a product<')->not->toContain('>Add a coupon<')->not->toContain('href="'.route('admin.catalog.products.create').'"');
});

it('puts headline figures under Today with their change since yesterday, and queues under Needs attention', function () {
    foreach ([FakeSalesUp::class, FakeSalesDown::class, FakeSalesSame::class, FakeSalesNew::class, FakeQueueWaiting::class, FakeQueueEmpty::class] as $tile) {
        app(DashboardRegistry::class)->add($tile);
    }

    $html = ($this->page)()->assertOk()->getContent();
    [$todayPart, $attentionPart] = explode('id="dash-attention"', $html);

    expect($todayPart)->toContain('id="dash-today"')->toContain('Fake sales up')->toContain('Up 50% from yesterday')->toContain('Fake sales down')->toContain('Down 75% from yesterday')
        ->toContain('Same as yesterday')->toContain('New since yesterday')->not->toContain('Fake queue')
        ->and($attentionPart)->toContain('Fake queue waiting')->toContain('Fake queue empty')->not->toContain('Fake sales');
});

it('says "All clear" for an empty queue and counts what is waiting', function () {
    app(DashboardRegistry::class)->add(FakeQueueWaiting::class);
    app(DashboardRegistry::class)->add(FakeQueueEmpty::class);

    $html = ($this->page)()->getContent();

    expect($html)->toContain('5 waiting')->and(preg_match('/Fake queue empty.{0,900}?All clear/s', $html))->toBe(1)->and(preg_match('/Fake queue waiting.{0,300}?All clear/s', $html))->toBe(0);
});

it('says nothing is waiting when every queue is empty', function () {
    app(DashboardRegistry::class)->add(FakeQueueEmpty::class);

    expect(($this->page)()->getContent())->toContain('Nothing is waiting');
});

it('shows a tile that cannot be worked out as unavailable, and the page still opens', function () {
    app(DashboardRegistry::class)->add(FakeQueueBroken::class);
    app(DashboardRegistry::class)->add(FakeQueueWaiting::class);

    $html = ($this->page)()->assertOk()->getContent();

    expect($html)->toContain('Fake queue broken')->toContain('Not available right now')->toContain('Fake queue waiting');
});

it('keeps every link of a figure pointing at the list it counts, with a name for screen readers', function () {
    app(DashboardRegistry::class)->add(FakeQueueWaiting::class);

    $html = ($this->page)()->getContent();

    expect($html)->toContain('href="'.route('admin.support.index').'"')->toContain('<span class="sr-only"> — Fake queue waiting</span>');
});

it('reads in Bangla: the greeting, the sections and the changes', function () {
    app(DashboardRegistry::class)->add(FakeSalesUp::class);
    app(DashboardRegistry::class)->add(FakeQueueEmpty::class);

    $html = ($this->page)($this->owner, '/bn/admin')->getContent();
    foreach (['greeting_morning', 'greeting_afternoon', 'greeting_evening', 'up_from_yesterday', 'down_from_yesterday', 'same_as_yesterday', 'new_since_yesterday', 'today', 'all_clear', 'view', 'shortcuts'] as $key) {
        expect(__('platform::productivity.dashboard.'.$key, [], 'bn'))->not->toBe('platform::productivity.dashboard.'.$key)->and(__('platform::productivity.dashboard.'.$key, [], 'en'))->not->toBe('platform::productivity.dashboard.'.$key);
    }
    expect(strlen($html))->toBeGreaterThan(1000);
});
