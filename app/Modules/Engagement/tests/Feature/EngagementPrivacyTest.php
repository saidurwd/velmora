<?php

declare(strict_types=1);

use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shopWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->amina = User::factory()->create();
    $this->bob = User::factory()->create();
    ($this->setStock)($this->tee, 0);
    $now = now();
    DB::table('wishlist_items')->insert([['user_id' => $this->amina->id, 'variant_id' => $this->lamp['variantId'], 'created_at' => $now], ['user_id' => $this->bob->id, 'variant_id' => $this->ebook['variantId'], 'created_at' => $now]]);
    DB::table('stock_alerts')->insert([['user_id' => $this->amina->id, 'variant_id' => $this->tee['variantId'], 'status' => 'pending', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $this->bob->id, 'variant_id' => $this->tee['variantId'], 'status' => 'pending', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now]]);
});

it('is part of every customer\'s data copy and erasure', function () {
    expect(collect(app(PersonalDataCatalog::class)->exporters())->map(fn ($e) => $e::class)->all())->toContain(EngagementPersonalData::class)
        ->and(collect(app(PersonalDataCatalog::class)->erasers())->map(fn ($e) => $e::class)->all())->toContain(EngagementPersonalData::class);
});

it('puts the customer\'s own saved items and alerts in their data copy, and nobody else\'s', function () {
    $copy = app(EngagementPersonalData::class)->export($this->amina->id);

    expect($copy['saved'])->toHaveCount(1)->and($copy['saved'][0]['item'])->toBe('Brass Lamp')->and($copy['stock_alerts'])->toHaveCount(1)->and($copy['stock_alerts'][0]['item'])->toBe('Cotton Tee')->and($copy['stock_alerts'][0]['status'])->toBe('pending')
        ->and(json_encode($copy))->not->toContain('Cooking e-book');
});

it('deletes the customer\'s wishlist and alerts when they are erased, and only theirs, and can be repeated', function () {
    $eraser = app(EngagementPersonalData::class);

    $eraser->erase($this->amina->id);
    $eraser->erase($this->amina->id);

    expect(DB::table('wishlist_items')->where('user_id', $this->amina->id)->count())->toBe(0)->and(DB::table('stock_alerts')->where('user_id', $this->amina->id)->count())->toBe(0)
        ->and(DB::table('wishlist_items')->where('user_id', $this->bob->id)->count())->toBe(1)->and(DB::table('stock_alerts')->where('user_id', $this->bob->id)->count())->toBe(1)
        ->and($eraser->blockers($this->amina->id))->toBe([]);
});
