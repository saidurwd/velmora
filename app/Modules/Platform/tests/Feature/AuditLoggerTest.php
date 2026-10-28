<?php

declare(strict_types=1);

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\RequestContext;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records who did what with request context', function () {
    $context = app(RequestContext::class);
    $context->requestId = 'req-1234567890';
    $context->ip = '203.0.113.9';

    app(AuditLogger::class)->record('price.updated', 'product_variant', ['price' => 100], ['price' => 120], new Actor(ActorType::Staff, 7, label: 'amina'), subjectId: 'var_1');

    $row = DB::table('audit_events')->first();

    expect($row->actor_type)->toBe('staff')
        ->and($row->actor_id)->toBe(7)
        ->and($row->action)->toBe('price.updated')
        ->and($row->subject_type)->toBe('product_variant')
        ->and($row->subject_id)->toBe('var_1')
        ->and($row->request_id)->toBe('req-1234567890')
        ->and($row->ip)->toBe('203.0.113.9')
        ->and(json_decode($row->after, true))->toBe(['price' => 120]);
});

it('redacts secrets before storing', function () {
    app(AuditLogger::class)->record('settings.changed', 'setting', ['gateway_password' => 'hunter2'], ['api_key' => 'abc', 'note' => 'call 01712345678']);

    $row = DB::table('audit_events')->first();

    expect($row->before)->toContain('[redacted]')->not->toContain('hunter2')
        ->and($row->after)->not->toContain('"abc"')->not->toContain('01712345678')->toContain('+88017****5678');
});

it('is append-only: UPDATE and DELETE are rejected by the database', function () {
    app(AuditLogger::class)->record('x.y');

    expect(fn () => DB::table('audit_events')->update(['action' => 'tampered']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('audit_events')->delete())->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('audit_events')->count())->toBe(1);
});
