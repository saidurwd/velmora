<?php

declare(strict_types=1);

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    config(['app.fallback_locale' => 'en']);
});

function translatable(array $rows): Model
{
    $model = new class extends Model
    {
        use HasPublicId, HasTranslations;

        protected $guarded = [];

        protected function translationModel(): string
        {
            return TranslationRow::class;
        }
    };

    $model->setRelation('translations', collect(array_map(fn (array $r) => new TranslationRow($r), $rows)));

    return $model;
}

class TranslationRow extends Model
{
    protected $guarded = [];
}

it('returns the requested locale when present', function () {
    $m = translatable([['locale' => 'en', 'name' => 'Shirt'], ['locale' => 'bn', 'name' => 'শার্ট']]);

    expect($m->translate('name', 'bn'))->toBe('শার্ট')
        ->and($m->translate('name', 'en'))->toBe('Shirt');
});

it('falls back bn -> en when bn is missing or empty (BD-32)', function () {
    $m = translatable([['locale' => 'en', 'name' => 'Shirt'], ['locale' => 'bn', 'name' => '']]);

    expect($m->translate('name', 'bn'))->toBe('Shirt')
        ->and($m->hasTranslation('name', 'bn'))->toBeFalse()
        ->and($m->hasTranslation('name', 'en'))->toBeTrue();
});

it('returns null when nothing is translated', function () {
    expect(translatable([])->translate('name', 'bn'))->toBeNull();
});

it('assigns a lowercase ULID public id and routes by it', function () {
    $m = translatable([]);
    $m::getEventDispatcher()->until('eloquent.creating: '.$m::class, $m);

    expect($m->public_id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($m->getRouteKeyName())->toBe('public_id');
});
